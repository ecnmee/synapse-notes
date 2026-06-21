<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolve conflitos entre factos semânticos activos.
 *
 * Um conflito existe quando, para o mesmo (tenant_id, entity), existem
 * múltiplos registos 'active' com claims diferentes — e um novo candidato
 * com confiança superior pretende substituir o facto existente.
 *
 * ─── Quando é chamado ─────────────────────────────────────────────────────────
 *
 * Chamado pelo {@see SemanticValidator} imediatamente antes de promover
 * um candidato para 'active'. Se detectar conflito, invoca
 * {@see SemanticMemory::supersede()} para revogar o facto antigo e criar
 * o novo — em vez de promover directamente.
 *
 * ─── Critério de conflito ────────────────────────────────────────────────────
 *
 * Existe conflito quando:
 *   1. Existe um facto 'active' para (tenant_id, entity).
 *   2. O claim desse facto é diferente do claim candidato.
 *   3. A confiança do candidato é >= à do facto existente.
 *
 * Se o candidato tiver confiança inferior ao facto activo, não substitui —
 * o facto mais confiante prevalece. O candidato é ignorado silenciosamente.
 *
 * ─── GOVERNANCE-5 ────────────────────────────────────────────────────────────
 *
 * O facto antigo nunca é apagado — fica com status 'superseded'.
 * A operação de substituição é delegada a {@see SemanticMemory::supersede()},
 * que garante a invariante por construção.
 *
 * ─── Exemplo ─────────────────────────────────────────────────────────────────
 *
 * Facto activo:  entity="IVA Angola", claim="14%",  confidence=0.85
 * Novo candidato: entity="IVA Angola", claim="15%",  confidence=0.90
 *
 * Resultado:
 *   - Facto antigo → superseded
 *   - Novo registo → active (claim="15%", confidence=0.90)
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class SemanticConflictResolver
{
    public function __construct(
        private readonly SemanticMemory $semanticMemory,
    ) {}

    /**
     * Verifica e resolve conflitos para (tenant_id, entity, claim).
     *
     * Devolve o resultado da resolução para que o caller possa decidir
     * se deve prosseguir com a promoção normal ou se o conflito já
     * foi resolvido internamente.
     *
     * @param  int    $tenantId
     * @param  string $entity
     * @param  string $claim       Claim do candidato a promover.
     * @param  float  $confidence  Confiança do candidato.
     * @param  string $source      Origem do candidato.
     * @return ConflictResolution
     */
    public function resolve(
        int    $tenantId,
        string $entity,
        string $claim,
        float  $confidence,
        string $source,
    ): ConflictResolution {
        $existing = $this->findActiveFact($tenantId, $entity);

        // Sem facto activo — sem conflito. Promoção normal.
        if ($existing === null) {
            return ConflictResolution::noConflict();
        }

        // Mesmo claim — facto já activo com este conteúdo. Nada a fazer.
        if ($existing->claim === $claim) {
            return ConflictResolution::alreadyActive();
        }

        // Claim diferente — conflito detectado.
        // Candidato com confiança inferior não substitui.
        if ($confidence < (float) $existing->confidence) {
            Log::info('[SemanticConflictResolver] Candidato rejeitado — confiança inferior ao facto activo.', [
                'tenant_id'           => $tenantId,
                'entity'              => $entity,
                'existing_claim'      => mb_substr($existing->claim, 0, 60),
                'existing_confidence' => $existing->confidence,
                'candidate_claim'     => mb_substr($claim, 0, 60),
                'candidate_confidence' => $confidence,
            ]);

            return ConflictResolution::rejected();
        }

        // Candidato com confiança >= facto activo — substitui via supersede().
        Log::info('[SemanticConflictResolver] Conflito resolvido — facto substituído.', [
            'tenant_id'       => $tenantId,
            'entity'          => $entity,
            'old_claim'       => mb_substr($existing->claim, 0, 60),
            'new_claim'       => mb_substr($claim, 0, 60),
            'old_confidence'  => $existing->confidence,
            'new_confidence'  => $confidence,
        ]);

        $this->semanticMemory->supersede(
            tenantId:      $tenantId,
            factId:        (int) $existing->id,
            newClaim:      $claim,
            newConfidence: $confidence,
            source:        $source,
        );

        return ConflictResolution::superseded();
    }

    /**
     * Procura o facto activo mais recente para (tenant_id, entity).
     *
     * Usa o índice composto (tenant_id, entity, status) adicionado em P5.1.
     */
    private function findActiveFact(int $tenantId, string $entity): ?object
    {
        return DB::table('agent_semantic_memory')
            ->where('tenant_id', $tenantId)
            ->where('entity', $entity)
            ->where('status', 'active')
            ->where('version', 'v2')
            ->orderByDesc('confidence')
            ->first(['id', 'claim', 'confidence']);
    }
}
