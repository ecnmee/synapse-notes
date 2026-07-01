<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CortexOS's Semantic Memory layer.
 *
 * Stores consolidated business facts extracted from episodes.
 * Complements the existing KB with knowledge derived from interactions.
 *
 * Invariant GOVERNANCE-5: facts are never deleted. Revocation marks
 * status='superseded' and creates a new fact. History stays auditable.
 *
 * Fields per fact:
 *   entity       - entity the fact refers to
 *   claim        - the fact itself
 *   confidence   - confidence score (0.0-1.0)
 *   source       - origin: 'kb_strong_match'|'llm_inference'|'operator_confirmed'
 *   validated_at - validation timestamp
 *   status       - 'candidate'|'active'|'superseded'
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class SemanticMemory
{
    /**
     * Loads all active facts for the tenant.
     *
     * @param  int $tenantId
     * @param  int $limit    Maximum number of facts to return.
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
            Log::warning('[SemanticMemory] loadActive failed', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return [];
        }
    }

    /**
     * Proposes a new semantic fact with 'candidate' status.
     *
     * Never activates directly, requires validation by
     * SemanticMemoryValidator before reaching 'active' status.
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
            Log::warning('[SemanticMemory] propose failed', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
                'entity'    => $entity,
            ]);
        }
    }

    /**
     * Revokes an active fact, marking it as superseded.
     *
     * Creates a new fact with the corrected content. The old fact
     * remains in the database for audit purposes, it's never deleted.
     *
     * @param  int    $tenantId
     * @param  int    $factId        ID of the fact to revoke.
     * @param  string $newClaim      New, corrected content for the fact.
     * @param  float  $newConfidence
     * @param  string $source        Origin of the correction.
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
            Log::error('[SemanticMemory] supersede failed', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
                'fact_id'   => $factId,
            ]);
        }
    }
}
