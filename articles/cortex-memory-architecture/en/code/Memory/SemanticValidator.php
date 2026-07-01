<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Validator for semantic candidates.
 *
 * Closes the semantic learning loop: promotes facts with 'candidate'
 * status to 'active', making them available to the agent via
 * {@see SemanticMemory::loadActive()} -> {@see MemoryBus::load()} ->
 * {@see \App\Services\V2\Agent\Kernel\AgentMemorySummary::$semantic}.
 *
 * --- Promotion criteria ---
 *
 * A fact is promoted when, for the same (tenant_id, entity, claim):
 *
 *   1. There are at least {@see MIN_CONFIRMATIONS} distinct candidates.
 *   2. The candidates' average confidence is >= {@see MIN_CONFIDENCE}.
 *
 * --- Conflict resolution (P5.1) ---
 *
 * Before promoting, {@see SemanticConflictResolver} checks whether an
 * 'active' fact already exists for the same entity with a different
 * claim:
 *
 *   - No conflict        -> normal promotion
 *   - Already active     -> skip (idempotency)
 *   - Conflict resolved   -> supersede() already created the new active fact
 *   - Rejected            -> candidate with lower confidence, skip
 *
 * --- Invariant GOVERNANCE-5 ---
 *
 * Candidates are never deleted or edited.
 * Promotion creates a new 'active' record, candidates remain as
 * 'candidate' for historical audit.
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
     * Validates and promotes a tenant's semantic candidates.
     *
     * For each eligible (entity, claim) group, consults the
     * ConflictResolver before promoting. The result determines the
     * next step.
     *
     * @param  int $tenantId
     * @return int Number of facts promoted or resolved in this call.
     */
    public function validate(int $tenantId): int
    {
        $promoted = 0;

        try {
            $candidates = $this->fetchEligibleGroups($tenantId);
        } catch (\Exception $e) {
            Log::error('[SemanticValidator] Failed to load candidates.', [
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
                    // already_active, superseded, or rejected,
                    // superseded counts as promoted (conflict resolved)
                    if ($resolution->status === 'superseded') {
                        $promoted++;
                    }
                    continue;
                }

                // no_conflict, normal promotion
                $this->promote($tenantId, $candidate);
                $promoted++;

                Log::info('[SemanticValidator] Fact promoted to active.', [
                    'tenant_id'      => $tenantId,
                    'entity'         => $candidate->entity,
                    'claim'          => mb_substr($candidate->claim, 0, 80),
                    'confirmations'  => $candidate->confirmations,
                    'avg_confidence' => $candidate->avg_confidence,
                ]);

            } catch (\Exception $e) {
                Log::warning('[SemanticValidator] Failed to process candidate.', [
                    'error'     => $e->getMessage(),
                    'tenant_id' => $tenantId,
                    'entity'    => $candidate->entity,
                ]);
            }
        }

        return $promoted;
    }

    /**
     * Groups candidates by (entity, claim) and filters by the minimum
     * criteria.
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
     * Creates the 'active' record from the aggregated candidate group.
     *
     * Does not alter the existing candidates (GOVERNANCE-5).
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
