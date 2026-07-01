<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use App\Services\V2\Agent\Kernel\AgentMemorySummary;
use App\Services\V2\Agent\Memory\Policy\PolicyObservationRepository;
use Illuminate\Support\Facades\Log;

/**
 * Single access facade for CortexOS's 4 memory layers.
 *
 * No component accesses a memory layer directly.
 * All access goes through this bus, which coordinates reads, writes
 * and consolidation across layers.
 *
 * The 4 layers:
 *   - Working    -> immediate session context
 *   - Episodic   -> compressed past experiences
 *   - Semantic   -> consolidated business facts
 *   - Procedural -> executable workflows with success_rate
 *
 * Operational telemetry:
 *   - Policy     -> policy observations via {@see PolicyObservationRepository}
 *                  (no candidate/active pipeline, direct persistence)
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class MemoryBus implements MemoryBusInterface
{
    public function __construct(
        private readonly WorkingMemoryInterface       $working,
        private readonly EpisodicMemoryInterface      $episodic,
        private readonly SemanticMemory               $semantic,
        private readonly ProceduralMemoryInterface    $procedural,
        private readonly PolicyObservationRepository  $policyObservations,
    ) {}

    /**
     * Loads a summary of the layers relevant to the current turn.
     *
     * Does not load the full memory, only extracts what's needed
     * to build the snapshot's {@see AgentMemorySummary}.
     *
     * @param  int    $tenantId
     * @param  string $sessionId
     * @param  string $query     User query for relevant semantic search.
     * @return AgentMemorySummary
     */
    public function load(int $tenantId, string $sessionId, string $query): AgentMemorySummary
    {
        try {
            return new AgentMemorySummary(
                working:    $this->working->load($tenantId, $sessionId),
                episodic:   $this->episodic->loadRelevant($tenantId, $query, limit: 3),
                semantic:   $this->semantic->loadActive($tenantId, limit: 10),
                procedural: $this->procedural->loadActive($tenantId),
            );
        } catch (\Exception $e) {
            Log::warning('[MemoryBus] load failed, returning empty memory', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
            ]);

            return new AgentMemorySummary();
        }
    }

    /**
     * Persists working memory updates after a turn.
     *
     * @param  int                   $tenantId
     * @param  string                $sessionId
     * @param  array<string, mixed>  $updates   Key/value pairs to update.
     */
    public function updateWorking(int $tenantId, string $sessionId, array $updates): void
    {
        try {
            $this->working->update($tenantId, $sessionId, $updates);
        } catch (\Exception $e) {
            Log::warning('[MemoryBus] updateWorking failed', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
            ]);
        }
    }

    /**
     * Persists a new episode after a complete execution (CLOSED).
     *
     * Called by the CognitiveMaintenance worker as a DeferredEffect.
     *
     * @param  int                  $tenantId
     * @param  string               $sessionId
     * @param  array<string, mixed> $episode   Raw episode data to compress.
     */
    public function persistEpisode(int $tenantId, string $sessionId, array $episode): void
    {
        try {
            $this->episodic->persist($tenantId, $sessionId, $episode);
        } catch (\Exception $e) {
            Log::error('[MemoryBus] persistEpisode failed', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
            ]);
        }
    }

    /**
     * Proposes a new semantic fact for validation.
     *
     * The fact enters with 'candidate' status, only becomes 'active' after
     * validation by SemanticMemoryValidator.
     *
     * @param  int    $tenantId
     * @param  string $entity
     * @param  string $claim
     * @param  float  $confidence
     * @param  string $source
     */
    public function proposeSemantic(
        int    $tenantId,
        string $entity,
        string $claim,
        float  $confidence,
        string $source,
    ): void {
        try {
            $this->semantic->propose($tenantId, $entity, $claim, $confidence, $source);
        } catch (\Exception $e) {
            Log::warning('[MemoryBus] proposeSemantic failed', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
                'entity'    => $entity,
            ]);
        }
    }

    /**
     * Persists a policy observation produced by the Learner.
     *
     * Policy observations are operational telemetry, not validatable
     * knowledge. That's why there's no candidate/active pipeline: the
     * observation is persisted directly to {@see PolicyObservationRepository}.
     *
     * The signature takes scalars, MemoryBus doesn't depend on objects from
     * the Learner namespace. The pattern follows {@see self::proposeSemantic()}.
     *
     * Failures are logged and swallowed, the Learner is a non-critical
     * subsystem and a telemetry failure shouldn't block the main flow.
     *
     * @param  int                  $tenantId  Originating tenant.
     * @param  string               $category  Observation type (e.g. "failure_recovery").
     * @param  string               $message   Observation description.
     * @param  string               $severity  Level: 'info' | 'warning' | 'critical'.
     * @param  string|null          $episodeId Originating episode, when available.
     * @param  array<string, mixed> $metadata  Additional structured evidence.
     */
    public function proposePolicy(
        int     $tenantId,
        string  $category,
        string  $message,
        string  $severity   = 'info',
        ?string $episodeId  = null,
        array   $metadata   = [],
    ): void {
        try {
            $this->policyObservations->store(
                tenantId:  $tenantId,
                category:  $category,
                message:   $message,
                severity:  $severity,
                episodeId: $episodeId,
                metadata:  $metadata,
            );
        } catch (\Exception $e) {
            Log::warning('[MemoryBus] proposePolicy failed', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'category'   => $category,
                'episode_id' => $episodeId,
            ]);
        }
    }

    /**
     * Proposes a new procedure for the Learner's validation pipeline.
     *
     * The procedure enters with 'candidate' status, never activates directly.
     *
     * @param  int                  $tenantId
     * @param  string               $trigger
     * @param  list<string>         $workflow   Sequence of tool names.
     * @param  string               $impactLevel 'low'|'high'
     */
    public function proposeProcedure(
        int    $tenantId,
        string $trigger,
        array  $workflow,
        string $impactLevel = 'low',
    ): void {
        try {
            $this->procedural->propose($tenantId, $trigger, $workflow, $impactLevel);
        } catch (\Exception $e) {
            Log::warning('[MemoryBus] proposeProcedure failed', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'trigger'    => $trigger,
            ]);
        }
    }

    /**
     * Records the outcome of a procedure execution.
     *
     * Mandatory boundary: no application-layer component (e.g.
     * {@see \App\Services\V2\Agent\Application\ExecuteAgentService}) talks
     * directly to {@see ProceduralMemory}, all access goes through here.
     *
     * --- Why propose() + recordOutcome(), and not just recordOutcome() ---
     *
     * {@see ProceduralMemory::recordOutcome()} only updates an already
     * existing procedure: `if (! $procedure) { return; }`, no log, no
     * error. Without a previously proposed candidate, recordOutcome() is a
     * silent no-op for any new trigger, and procedural learning never
     * starts.
     *
     * That's why this method always calls {@see ProceduralMemory::propose()}
     * first. propose() is idempotent by (tenant_id, trigger, version), it
     * returns with no effect if a candidate/scored/validated/active already
     * exists for the trigger, so calling it on every execution is safe: it
     * creates the candidate on the trigger's first occurrence, and is a
     * no-op afterwards. Then, recordOutcome() always updates the
     * procedure's metrics (newly created or pre-existing).
     *
     * --- impactLevel ---
     *
     * Fixed at 'low'. There's currently no approved heuristic to derive
     * impact_level from the intent, inventing one here would mean
     * deciding, without a mandate, which workflow is risky enough to
     * require manual approval. Direct consequence: every procedure
     * auto-proposed through this path is eligible for automatic activation
     * ({@see ProceduralMemory::AUTO_ACTIVATE_THRESHOLD}) once it reaches
     * the minimum sample size. Revisit once a risk classification
     * criterion exists.
     *
     * @param  int           $tenantId
     * @param  string        $trigger   Intent identifier (e.g. $goal->metadata['intent']).
     * @param  list<string>  $workflow  Sequence of tool names observed during execution.
     * @param  bool          $success   Whether the execution resolved at least one goal.
     */
    public function recordProceduralOutcome(int $tenantId, string $trigger, array $workflow, bool $success): void
    {
        try {
            $this->procedural->propose($tenantId, $trigger, $workflow, impactLevel: 'low');
            $this->procedural->recordOutcome($tenantId, $trigger, $success);
        } catch (\Exception $e) {
            Log::warning('[MemoryBus] recordProceduralOutcome failed', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'trigger'    => $trigger,
            ]);
        }
    }

    /**
     * Direct access to the Working layer for session operations.
     */
    public function working(): WorkingMemoryInterface
    {
        return $this->working;
    }

    /**
     * Direct access to the Procedural layer for the ToolRouter (Layer 2).
     */
    public function procedural(): ProceduralMemoryInterface
    {
        return $this->procedural;
    }
}
