<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Camada de Semantic Memory do CortexOS.
 *
 * Armazena factos consolidados sobre o negócio extraídos de episódios.
 * Complementa a KB existente com conhecimento derivado de interacções.
 *
 * Invariante GOVERNANCE-5: factos nunca são apagados. Revogação marca
 * status='superseded' e cria um novo facto. O histórico é auditável.
 *
 * Campos por facto:
 *   entity       — entidade sobre a qual o facto se refere
 *   claim        — o facto em si
 *   confidence   — score de confiança (0.0–1.0)
 *   source       — origem: 'kb_strong_match'|'llm_inference'|'operator_confirmed'
 *   validated_at — timestamp de validação
 *   status       — 'candidate'|'active'|'superseded'
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class SemanticMemory
{
    /**
     * Carrega todos os factos activos do tenant.
     *
     * @param  int $tenantId
     * @param  int $limit    Máximo de factos a retornar.
     * @return array<int, array<string, mixed>>
     */
    public function loadActive(int $tenantId, int $limit = 10): array
    {
        try {
            return DB::table('agent_semantic_memory')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->where('version', 'v2')
                ->orderByDesc('confidence')
                ->limit($limit)
                ->get(['entity', 'claim', 'confidence', 'source', 'validated_at'])
                ->map(fn($row) => (array) $row)
                ->toArray();
        } catch (\Exception $e) {
            Log::warning('[SemanticMemory] loadActive falhou', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return [];
        }
    }

    /**
     * Propõe um novo facto semântico com status 'candidate'.
     *
     * Nunca activa directamente — requer validação pelo
     * SemanticMemoryValidator antes de atingir status 'active'.
     *
     * @param  int    $tenantId
     * @param  string $entity
     * @param  string $claim
     * @param  float  $confidence
     * @param  string $source
     */
    public function propose(
        int    $tenantId,
        string $entity,
        string $claim,
        float  $confidence,
        string $source,
    ): void {
        try {
            DB::table('agent_semantic_memory')->insert([
                'tenant_id'    => $tenantId,
                'entity'       => $entity,
                'claim'        => $claim,
                'confidence'   => $confidence,
                'source'       => $source,
                'status'       => 'candidate',
                'version'      => 'v2',
                'validated_at' => null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('[SemanticMemory] propose falhou', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
                'entity'    => $entity,
            ]);
        }
    }

    /**
     * Revoga um facto activo, marcando-o como superseded.
     *
     * Cria um novo facto com o conteúdo corrigido. O facto antigo
     * permanece na base de dados para auditoria — nunca é apagado.
     *
     * @param  int    $tenantId
     * @param  int    $factId        ID do facto a revogar.
     * @param  string $newClaim      Novo conteúdo correcto do facto.
     * @param  float  $newConfidence
     * @param  string $source        Origem da correcção.
     */
    public function supersede(
        int    $tenantId,
        int    $factId,
        string $newClaim,
        float  $newConfidence,
        string $source,
    ): void {
        try {
            DB::transaction(function () use ($tenantId, $factId, $newClaim, $newConfidence, $source): void {
                $old = DB::table('agent_semantic_memory')
                    ->where('id', $factId)
                    ->where('tenant_id', $tenantId)
                    ->first();

                if (! $old) {
                    return;
                }

                DB::table('agent_semantic_memory')
                    ->where('id', $factId)
                    ->update(['status' => 'superseded', 'updated_at' => now()]);

                DB::table('agent_semantic_memory')->insert([
                    'tenant_id'          => $tenantId,
                    'entity'             => $old->entity,
                    'claim'              => $newClaim,
                    'confidence'         => $newConfidence,
                    'source'             => $source,
                    'status'             => 'active',
                    'supersedes_fact_id' => $factId,
                    'version'            => 'v2',
                    'validated_at'       => now(),
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            });
        } catch (\Exception $e) {
            Log::error('[SemanticMemory] supersede falhou', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
                'fact_id'   => $factId,
            ]);
        }
    }
}
