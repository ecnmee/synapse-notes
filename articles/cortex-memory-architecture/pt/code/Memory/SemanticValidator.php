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
 *   2. A confiança média dos candidatos é >= {@see MIN_CONFIDENCE}.
 *
 * ─── Resolução de conflitos (P5.1) ────────────────────────────────────────────
 *
 * Antes de promover, o {@see SemanticConflictResolver} verifica se existe
 * um facto 'active' para a mesma entity com claim diferente:
 *
 *   - Sem conflito       → promoção normal
 *   - Já activo          → ignorar (idempotência)
 *   - Conflito resolvido → supersede() já criou o novo facto active
 *   - Rejeitado          → candidato com confiança inferior, ignorar
 *
 * ─── Invariante GOVERNANCE-5 ────────────────────────────────────────────────
 *
 * Candidatos nunca são apagados nem alterados.
 * A promoção cria um novo registo 'active' — os candidatos permanecem
 * como 'candidate' para auditoria histórica.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class SemanticValidator
{
    private const MIN_CONFIRMATIONS = 3;
    private const MIN_CONFIDENCE    = 0.80;

    public function __construct(
        private readonly SemanticConflictResolver $conflictResolver,
    ) {}

    /**
     * Valida e promove candidatos semânticos de um tenant.
     *
     * Para cada grupo (entity, claim) elegível, consulta o ConflictResolver
     * antes de promover. O resultado determina o próximo passo.
     *
     * @param  int $tenantId
     * @return int Número de factos promovidos ou resolvidos nesta chamada.
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
            try {
                $resolution = $this->conflictResolver->resolve(
                    tenantId:   $tenantId,
                    entity:     $candidate->entity,
                    claim:      $candidate->claim,
                    confidence: (float) $candidate->avg_confidence,
                    source:     $candidate->canonical_source,
                );

                if ($resolution->shouldSkip()) {
                    // already_active, superseded ou rejected —
                    // superseded conta como promovido (conflito resolvido)
                    if ($resolution->status === 'superseded') {
                        $promoted++;
                    }
                    continue;
                }

                // no_conflict — promoção normal
                $this->promote($tenantId, $candidate);
                $promoted++;

                Log::info('[SemanticValidator] Facto promovido para active.', [
                    'tenant_id'      => $tenantId,
                    'entity'         => $candidate->entity,
                    'claim'          => mb_substr($candidate->claim, 0, 80),
                    'confirmations'  => $candidate->confirmations,
                    'avg_confidence' => $candidate->avg_confidence,
                ]);

            } catch (\Exception $e) {
                Log::warning('[SemanticValidator] Falha ao processar candidato.', [
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
     * @return array<object{entity: string, claim: string, confirmations: int, avg_confidence: float, canonical_source: string}>
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
     * Cria o registo 'active' a partir do grupo de candidatos agregado.
     *
     * Não altera os candidatos existentes (GOVERNANCE-5).
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
