<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Validador de candidatos semânticos.
 *
 * Fecha o ciclo de aprendizagem semântica: promove factos com status
 * 'candidate' para 'active', tornando-os disponíveis ao agente via
 * {@see SemanticMemory::loadActive()} → {@see MemoryBus::load()} →
 * {@see \App\Services\V2\Agent\Kernel\AgentMemorySummary::$semantic}.
 *
 * ─── Critérios de promoção ────────────────────────────────────────────────────
 *
 * Um facto é promovido quando, para o mesmo (tenant_id, entity, claim):
 *
 *   1. Existe pelo menos {@see MIN_CONFIRMATIONS} candidatos distintos.
 *      Confirmações são contadas por registos candidate independentes — cada
 *      chamada a SemanticMemory::propose() para a mesma (entity, claim) conta
 *      como uma confirmação adicional, mesmo sem campo sample_size na tabela.
 *
 *   2. A confiança média dos candidatos é >= {@see MIN_CONFIDENCE}.
 *
 * ─── Invariante GOVERNANCE-5 ────────────────────────────────────────────────
 *
 * Candidatos nunca são apagados nem marcados como 'active'.
 * A promoção cria um novo registo com status 'active' — os candidatos
 * permanecem como 'candidate' para auditoria histórica.
 *
 * Isto é diferente do pipeline procedimental, que actualiza o mesmo registo.
 * A razão: semântica segue event sourcing — cada candidato é um evento
 * observado independentemente. O registo 'active' é uma projecção derivada.
 *
 * ─── Idempotência ────────────────────────────────────────────────────────────
 *
 * validate() é seguro para chamar múltiplas vezes para o mesmo tenant.
 * A query verifica se já existe um registo 'active' para (entity, claim) —
 * se existir, não cria duplicado.
 *
 * ─── Quando chamar ───────────────────────────────────────────────────────────
 *
 * Tipicamente chamado por um job assíncrono (ex: SemanticValidationJob)
 * agendado periodicamente — não no caminho crítico de execução.
 * Pode também ser chamado directamente em testes ou via Artisan command.
 *
 * ─── Relação com SemanticMemory::supersede() ────────────────────────────────
 *
 * supersede() existe para correcção manual de factos activos — operador
 * ou sistema externo que detecta erro num facto já promovido.
 * validate() é o pipeline automático de promoção de candidatos.
 * Os dois operam em momentos diferentes e não interferem.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class SemanticValidator
{
    /**
     * Número mínimo de candidatos independentes para o mesmo (entity, claim)
     * antes de promover para 'active'.
     *
     * Valor 2: exige que o facto apareça em pelo menos 2 episódios distintos
     * antes de ser considerado conhecimento estável. Evita que uma única
     * afirmação isolada contamine a memória activa.
     *
     * Revisar para valor superior (ex: 3) quando o volume de execuções
     * por tenant aumentar — hoje o valor baixo é adequado ao sample size
     * esperado em produção inicial.
     * por isso 0.80 é o mínimo para que a média do grupo seja considerada
     * conhecimento estável — não apenas plausível.
     */
    private const MIN_CONFIRMATIONS = 3;

    /**
     * Confiança média mínima dos candidatos para promoção.
     *
     * Alinhado com SemanticConsolidator::MIN_CONFIDENCE (0.60) mas
     * deliberadamente acima do SemanticConsolidator::MIN_CONFIDENCE (0.60):
     * candidatos que chegam aqui já passaram o filtro de consolidação,
     * por isso 0.80 é o mínimo para que a média do grupo seja considerada
     * conhecimento estável — não apenas plausível.
     */
    private const MIN_CONFIDENCE = 0.80;

    /**
     * Valida e promove candidatos semânticos de um tenant.
     *
     * Percorre todos os grupos (entity, claim) com candidatos suficientes
     * e cria registos 'active' para os que passam os dois critérios.
     *
     * Falhas individuais de promoção são registadas e não interrompem
     * o processamento dos restantes candidatos.
     *
     * @param  int $tenantId
     * @return int Número de factos promovidos nesta chamada.
     * por isso 0.80 é o mínimo para que a média do grupo seja considerada
     * conhecimento estável — não apenas plausível.
     */
    public function validate(int $tenantId): int
    {
        $promoted = 0;

        try {
            $candidates = $this->fetchEligibleGroups($tenantId);
        } catch (\Exception $e) {
            Log::error('[SemanticValidator] Falha ao carregar candidatos.', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return 0;
        }

        foreach ($candidates as $candidate) {
            if ($this->alreadyActive($tenantId, $candidate->entity, $candidate->claim)) {
                continue;
            }

            try {
                $this->promote($tenantId, $candidate);
                $promoted++;

                Log::info('[SemanticValidator] Facto promovido para active.', [
                    'tenant_id'    => $tenantId,
                    'entity'       => $candidate->entity,
                    'claim'        => mb_substr($candidate->claim, 0, 80),
                    'confirmations' => $candidate->confirmations,
                    'avg_confidence' => $candidate->avg_confidence,
                ]);
            } catch (\Exception $e) {
                Log::warning('[SemanticValidator] Falha ao promover facto.', [
                    'error'     => $e->getMessage(),
                    'tenant_id' => $tenantId,
                    'entity'    => $candidate->entity,
                ]);
            }
        }

        return $promoted;
    }

    /**
     * Agrupa candidatos por (entity, claim) e filtra pelos critérios mínimos.
     *
     * A query devolve apenas grupos que:
     *   - têm >= MIN_CONFIRMATIONS candidatos
     *   - têm confiança média >= MIN_CONFIDENCE
     *
     * O source mais frequente é usado como source canónico do registo active.
     *
     * @return array<object{entity: string, claim: string, confirmations: int, avg_confidence: float, canonical_source: string}>
     * por isso 0.80 é o mínimo para que a média do grupo seja considerada
     * conhecimento estável — não apenas plausível.
     */
    private function fetchEligibleGroups(int $tenantId): array
    {
        return DB::table('agent_semantic_memory')
            ->select([
                'entity',
                'claim',
                DB::raw('COUNT(*) as confirmations'),
                DB::raw('AVG(confidence) as avg_confidence'),
                DB::raw('MAX(source) as canonical_source'),
            ])
            ->where('tenant_id', $tenantId)
            ->where('status', 'candidate')
            ->where('version', 'v2')
            ->groupBy('entity', 'claim')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_CONFIRMATIONS])
            ->havingRaw('AVG(confidence) >= ?', [self::MIN_CONFIDENCE])
            ->get()
            ->all();
    }

    /**
     * Verifica se já existe um registo 'active' para (entity, claim).
     *
     * Garante idempotência — nunca cria duplicados de factos activos.
     * por isso 0.80 é o mínimo para que a média do grupo seja considerada
     * conhecimento estável — não apenas plausível.
     */
    private function alreadyActive(int $tenantId, string $entity, string $claim): bool
    {
        return DB::table('agent_semantic_memory')
            ->where('tenant_id', $tenantId)
            ->where('entity', $entity)
            ->where('claim', $claim)
            ->where('status', 'active')
            ->where('version', 'v2')
            ->exists();
    }

    /**
     * Cria o registo 'active' a partir do grupo de candidatos agregado.
     *
     * Não altera os candidatos existentes (GOVERNANCE-5).
     * A confiança do registo activo é a média dos candidatos.
     * O source é o mais frequente entre os candidatos do grupo.
     * por isso 0.80 é o mínimo para que a média do grupo seja considerada
     * conhecimento estável — não apenas plausível.
     */
    private function promote(int $tenantId, object $candidate): void
    {
        DB::table('agent_semantic_memory')->insert([
            'tenant_id'    => $tenantId,
            'entity'       => $candidate->entity,
            'claim'        => $candidate->claim,
            'confidence'   => round((float) $candidate->avg_confidence, 4),
            'source'       => $candidate->canonical_source,
            'status'       => 'active',
            'version'      => 'v2',
            'validated_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
