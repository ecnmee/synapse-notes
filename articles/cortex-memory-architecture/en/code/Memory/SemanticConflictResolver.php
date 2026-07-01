<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves conflicts between active semantic facts.
 *
 * A conflict exists when, for the same (tenant_id, entity), there are
 * multiple 'active' records with different claims, and a new candidate
 * with higher confidence wants to replace the existing fact.
 *
 * --- When it's called ---
 *
 * Called by {@see SemanticValidator} immediately before promoting a
 * candidate to 'active'. If it detects a conflict, it invokes
 * {@see SemanticMemory::supersede()} to revoke the old fact and create
 * the new one, instead of promoting directly.
 *
 * --- Conflict criterion ---
 *
 * A conflict exists when:
 *   1. There's an 'active' fact for (tenant_id, entity).
 *   2. That fact's claim differs from the candidate claim.
 *   3. The candidate's confidence is >= the existing fact's confidence.
 *
 * If the candidate has lower confidence than the active fact, it does
 * not replace it, the more confident fact prevails. The candidate is
 * silently ignored.
 *
 * --- GOVERNANCE-5 ---
 *
 * The old fact is never deleted, it becomes 'superseded'.
 * The replacement operation is delegated to
 * {@see SemanticMemory::supersede()}, which guarantees the invariant by
 * construction.
 *
 * --- Example ---
 *
 * Active fact:    entity="Angola VAT", claim="14%", confidence=0.85
 * New candidate:  entity="Angola VAT", claim="15%", confidence=0.90
 *
 * Result:
 *   - Old fact -> superseded
 *   - New record -> active (claim="15%", confidence=0.90)
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
     * Checks and resolves conflicts for (tenant_id, entity, claim).
     *
     * Returns the resolution result so the caller can decide whether to
     * proceed with normal promotion or whether the conflict has already
     * been resolved internally.
     *
     * @param  int    $tenantId
     * @param  string $entity
     * @param  string $claim       Claim of the candidate to promote.
     * @param  float  $confidence  Candidate's confidence.
     * @param  string $source      Candidate's origin.
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

        // No active fact, no conflict. Normal promotion.
        if ($existing === null) {
            return ConflictResolution::noConflict();
        }

        // Same claim, fact already active with this content. Nothing to do.
        if ($existing->claim === $claim) {
            return ConflictResolution::alreadyActive();
        }

        // Different claim, conflict detected.
        // Candidate with lower confidence does not replace.
        if ($confidence < (float) $existing->confidence) {
            Log::info('[SemanticConflictResolver] Candidate rejected, lower confidence than active fact.', [
                'tenant_id'            => $tenantId,
                'entity'               => $entity,
                'existing_claim'       => mb_substr($existing->claim, 0, 60),
                'existing_confidence'  => $existing->confidence,
                'candidate_claim'      => mb_substr($claim, 0, 60),
                'candidate_confidence' => $confidence,
            ]);

            return ConflictResolution::rejected();
        }

        // Candidate confidence >= active fact, replace via supersede().
        Log::info('[SemanticConflictResolver] Conflict resolved, fact replaced.', [
            'tenant_id'      => $tenantId,
            'entity'         => $entity,
            'old_claim'      => mb_substr($existing->claim, 0, 60),
            'new_claim'      => mb_substr($claim, 0, 60),
            'old_confidence' => $existing->confidence,
            'new_confidence' => $confidence,
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
     * Finds the most recent active fact for (tenant_id, entity).
     *
     * Uses the composite index (tenant_id, entity, status) added in P5.1.
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
