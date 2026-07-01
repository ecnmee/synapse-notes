<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Episodic;

use App\Services\V2\Agent\Kernel\AgentContext;
use App\Services\V2\Agent\Kernel\ExecutionMetrics;
use App\Services\V2\Agent\Kernel\ToolTraceEntry;
use App\Services\V2\Agent\Planning\AlignmentResult;
use App\Services\V2\Agent\Planning\ExpansionTrace;
use App\Services\V2\Agent\Reflection\ReflectionResult;
use DateTimeImmutable;
use InvalidArgumentException;
use Illuminate\Support\Str;

/**
 * Immutable historical record of a complete agent execution.
 *
 * An Episode is a fact, not an interpretation. It answers exclusively:
 * "What happened in this execution?"
 *
 * It contains no memoryUpdates, insights, procedure candidates, or any
 * form of derived knowledge. Those belong to the Learner and Tier 2,
 * which produce them from already-persisted episodes.
 *
 * --- Explicit boundary: execution vs. cognition ---
 *
 * Episode                    Learner / Tier 2
 * -------------------------  ---------------------------------
 * What happened?             What did we learn?
 * Immutable after creation   Produces new artifacts
 * Observational               Interpretive
 * Independent of the FSM      Independent of the Episode
 *
 * --- Lifecycle ---
 *
 * 1. Runtime reaches COMPLETE or HANDOFF/TIMEOUT.
 * 2. Caller determines the EpisodeOutcome from the ReflectionResult and final state.
 * 3. Episode::fromExecution() builds the episode from canonical sources.
 * 4. EpisodeRepository::store() persists the episode.
 * 5. Learner reads episodes via EpisodeRepository for historical analysis.
 *
 * --- Source of truth for each field ---
 *
 * metrics      -> ReflectionResult::$delta (final metrics post-Tier1, not
 *                AgentContext's, which reflect the state at the start of
 *                the current iteration).
 * alignedGoals -> AlignmentResult::$alignedGoalIds
 * traces       -> AlignmentResult::$traces
 * revision     -> AgentContext::$strategicGoalsRevision
 *
 * --- ReflectionResult is always mandatory ---
 *
 * An Episode can only be created from cognitively complete executions,
 * i.e. executions that went through REFLECT and produced a
 * ReflectionResult. Executions interrupted before reflection (policy
 * denied, handoff, timeout) do not generate an Episode.
 *
 * This is intentional: the Learner only learns from complete cognitive
 * cycles. The persist_agent_result handler checks: if reflectionResult
 * === null, it does not create an Episode, it only persists AgentResult.
 *
 * --- Relationship with agent_strategic_goal_metrics ---
 *
 * Episodes and agent_strategic_goal_metrics are orthogonal and
 * complementary. Episodes are event sourcing, they record what
 * happened. agent_strategic_goal_metrics is a materialized projection,
 * it aggregates counters. AlignmentCoverageRecorder writes to the
 * projection; EpisodeRepository writes to the episodes. Both must be
 * called within the same transactional context.
 *
 * @package App\Services\V2\Agent\Memory\Episodic
 * @author  Eduardo Costa Nkuansambu
 */
final readonly class Episode
{
    /**
     * @param list<string>         $alignedGoals    IDs of the strategic goals aligned with the session.
     * @param list<ExpansionTrace> $alignmentTraces Full expansion trace for each evaluated goal.
     * @param list<ToolTraceEntry> $toolTrace       Sequence of tools executed in this episode.
     */
    public function __construct(
        public string            $episodeId,
        public int               $tenantId,
        public string            $sessionId,
        public string            $input,
        public array             $alignedGoals,
        public array             $alignmentTraces,
        public array             $toolTrace,
        public ExecutionMetrics  $metrics,
        public string            $strategicRevision,
        public EpisodeOutcome    $outcome,
        public DateTimeImmutable $createdAt,
    ) {
        if (trim($this->episodeId) === '') {
            throw new InvalidArgumentException('Episode id cannot be empty.');
        }

        if ($this->tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be a positive integer.');
        }

        if (trim($this->sessionId) === '') {
            throw new InvalidArgumentException('Session id cannot be empty.');
        }

        if (trim($this->strategicRevision) === '') {
            throw new InvalidArgumentException('Strategic revision cannot be empty.');
        }

        // Validate that all aligned goals are non-empty strings
        foreach ($this->alignedGoals as $goalId) {
            if (! is_string($goalId) || trim($goalId) === '') {
                throw new InvalidArgumentException(
                    'All aligned goals must be non-empty strings.'
                );
            }
        }

        foreach ($this->alignmentTraces as $trace) {
            if (! $trace instanceof ExpansionTrace) {
                throw new InvalidArgumentException(
                    'All alignment traces must be ExpansionTrace instances.'
                );
            }
        }

        foreach ($this->toolTrace as $entry) {
            if (! $entry instanceof ToolTraceEntry) {
                throw new InvalidArgumentException(
                    'All toolTrace entries must be ToolTraceEntry instances.'
                );
            }
        }
    }

    /**
     * Builds an Episode from the canonical outputs of the execution phases.
     *
     * ReflectionResult is the source of truth for metrics, it contains
     * the final metrics updated by Tier 1, while
     * AgentContext::$executionMetrics reflects the state before the
     * last reflection iteration.
     *
     * EpisodeOutcome isn't automatically derivable, it depends on the
     * FSM's final state, which the caller (Runtime or COMPLETE handler)
     * knows and passes explicitly. This keeps the Episode from knowing
     * the FSM's topology.
     *
     * ReflectionResult is ALWAYS mandatory. If null, the handler must
     * not call this method, it should only persist AgentResult, not an
     * Episode.
     */
    public static function fromExecution(
        AgentContext     $context,
        AlignmentResult  $alignment,
        ReflectionResult $reflection,
        EpisodeOutcome   $outcome,
        array            $toolTrace = [],
    ): self {
        // Final metrics: read from Tier 1's delta.
        // Tier 1 always guarantees updatedMetrics, see
        // ReflectionEngineTier1::updateMetrics(). There's no intentional
        // fallback: missing metrics indicate a Tier 1 bug, not a normal
        // state to silence.
        $metrics = $reflection->delta->updatedMetrics
            ?? throw new \LogicException(
                'ReflectionResult delta has no updatedMetrics. '
                . 'ReflectionEngineTier1::reflect() must always produce ExecutionMetrics.'
            );

        return new self(
            episodeId:         self::generateId(),
            tenantId:          $context->tenantId,
            sessionId:         $context->sessionId,
            input:             $context->input,
            alignedGoals:      $alignment->alignedGoalIds,
            alignmentTraces:   $alignment->traces,
            toolTrace:         $toolTrace,
            metrics:           $metrics,
            strategicRevision: $context->strategicGoalsRevision,
            outcome:           $outcome,
            createdAt:         new DateTimeImmutable(),
        );
    }

    /**
     * Indicates whether the execution had at least one aligned strategic goal.
     */
    public function hasStrategicAlignment(): bool
    {
        return ! empty($this->alignedGoals);
    }

    /**
     * Indicates whether any alignment occurred exclusively through synonym expansion.
     *
     * Useful for the Learner to measure the SynonymExpander's incremental
     * contribution in real executions, not just in aggregated coverage
     * metrics.
     */
    public function hasExpansionOnlyAlignment(): bool
    {
        foreach ($this->alignmentTraces as $trace) {
            if ($trace->matchedBySynonymOnly()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'episode_id'         => $this->episodeId,
            'tenant_id'          => $this->tenantId,
            'session_id'         => $this->sessionId,
            'input'              => $this->input,
            'aligned_goals'      => $this->alignedGoals,
            'alignment_traces'   => array_map(
                static fn (ExpansionTrace $t): array => $t->toArray(),
                $this->alignmentTraces,
            ),
            'tool_trace'         => array_map(
                static fn (ToolTraceEntry $t): array => $t->toArray(),
                $this->toolTrace,
            ),
            'metrics'            => $this->metrics->toArray(),
            'strategic_revision' => $this->strategicRevision,
            'outcome'            => $this->outcome->value,
            'created_at'         => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Rebuilds an Episode from a serialized array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['episode_id', 'tenant_id', 'session_id', 'input', 'metrics', 'strategic_revision', 'outcome', 'created_at'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    sprintf('Episode payload missing required field "%s".', $field)
                );
            }
        }

        // Validate types before use
        if (! is_string($data['episode_id']) || trim($data['episode_id']) === '') {
            throw new InvalidArgumentException('episode_id must be a non-empty string.');
        }

        if (! is_int($data['tenant_id']) || $data['tenant_id'] <= 0) {
            throw new InvalidArgumentException('tenant_id must be a positive integer.');
        }

        if (! is_string($data['session_id']) || trim($data['session_id']) === '') {
            throw new InvalidArgumentException('session_id must be a non-empty string.');
        }

        if (! is_string($data['input'])) {
            throw new InvalidArgumentException('input must be a string.');
        }

        if (! is_array($data['metrics'])) {
            throw new InvalidArgumentException('metrics must be an array.');
        }

        if (! is_string($data['strategic_revision']) || trim($data['strategic_revision']) === '') {
            throw new InvalidArgumentException('strategic_revision must be a non-empty string.');
        }

        if (! is_string($data['outcome'])) {
            throw new InvalidArgumentException('outcome must be a string.');
        }

        if (! is_string($data['created_at'])) {
            throw new InvalidArgumentException('created_at must be a string.');
        }

        // Parse and validate traces
        $traces = [];
        foreach ($data['alignment_traces'] ?? [] as $index => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException(
                    sprintf('alignment_traces[%d] must be an array.', $index)
                );
            }
            $traces[] = ExpansionTrace::fromArray($raw);
        }

        // Validate and normalize aligned_goals
        $alignedGoals = [];
        if (is_array($data['aligned_goals'] ?? null)) {
            foreach ($data['aligned_goals'] as $goalId) {
                if (! is_string($goalId) || trim($goalId) === '') {
                    throw new InvalidArgumentException(
                        'All aligned goals must be non-empty strings.'
                    );
                }
                $alignedGoals[] = $goalId;
            }
        }

        // Parse timestamp
        try {
            $createdAt = new DateTimeImmutable((string) $data['created_at']);
        } catch (\Exception) {
            throw new InvalidArgumentException('Invalid created_at value in Episode payload.');
        }

        // Parse outcome enum
        try {
            $episodeOutcome = EpisodeOutcome::from($data['outcome']);
        } catch (\ValueError) {
            throw new InvalidArgumentException(
                sprintf('outcome "%s" is not a valid EpisodeOutcome.', $data['outcome'])
            );
        }

        // Parse metrics
        try {
            $metrics = ExecutionMetrics::fromArray($data['metrics']);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException(
                'Invalid metrics payload: ' . $e->getMessage(),
                previous: $e,
            );
        }

        // Parse tool_trace, optional field for compatibility with episodes
        // persisted before P4.1 (no tool_trace in the table).
        $toolTrace = [];
        foreach ($data['tool_trace'] ?? [] as $index => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException(
                    sprintf('tool_trace[%d] must be an array.', $index)
                );
            }
            $toolTrace[] = ToolTraceEntry::fromArray($raw);
        }

        return new self(
            episodeId:         $data['episode_id'],
            tenantId:          $data['tenant_id'],
            sessionId:         $data['session_id'],
            input:             $data['input'],
            alignedGoals:      $alignedGoals,
            alignmentTraces:   $traces,
            toolTrace:         $toolTrace,
            metrics:           $metrics,
            strategicRevision: $data['strategic_revision'],
            outcome:           $episodeOutcome,
            createdAt:         $createdAt,
        );
    }

    /**
     * Generates a unique, sortable ID for the episode via ULID.
     *
     * ULIDs are sortable by timestamp, making historical queries and
     * temporal indexing easier without relying on a local clock.
     */
    private static function generateId(): string
    {
        return 'ep_' . Str::ulid()->toString();
    }
}
