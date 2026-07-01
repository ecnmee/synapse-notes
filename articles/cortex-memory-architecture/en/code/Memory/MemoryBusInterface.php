<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use App\Services\V2\Agent\Kernel\AgentMemorySummary;

/**
 * Access contract for CortexOS's memory bus.
 *
 * Extracted from {@see MemoryBus} to allow substitution in tests without
 * depending on Reflection or Mockery with additional extensions.
 *
 * --- Why this interface exists ---
 *
 * MemoryBus is `final` by design, the correct extension mechanism is to
 * implement this interface from scratch, not subclass MemoryBus. Any
 * consumer that depends on MemoryBus directly is impossible to isolate
 * in unit tests without touching its concrete dependencies (WorkingMemory,
 * EpisodicMemory, etc.), which in turn depend on Laravel facades.
 *
 * By depending on this interface, consumers become isolable with a
 * simple createMock().
 *
 * --- Methods included ---
 *
 * Only the methods used by direct consumers of the factory and the agent.
 * Internal access methods (working(), procedural()) are not part of the
 * public contract, they remain on MemoryBus as a concrete implementation
 * detail.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
interface MemoryBusInterface
{
    /**
     * Loads a summary of the layers relevant to the current turn.
     *
     * @param  int    $tenantId
     * @param  string $sessionId
     * @param  string $query     User query for relevant semantic search.
     * @return AgentMemorySummary
     */
    public function load(int $tenantId, string $sessionId, string $query): AgentMemorySummary;

    /**
     * Persists working memory updates after a turn.
     *
     * @param  int                   $tenantId
     * @param  string                $sessionId
     * @param  array<string, mixed>  $updates
     */
    public function updateWorking(int $tenantId, string $sessionId, array $updates): void;

    /**
     * Persists a new episode after a complete execution (CLOSED).
     *
     * @param  int                  $tenantId
     * @param  string               $sessionId
     * @param  array<string, mixed> $episode
     */
    public function persistEpisode(int $tenantId, string $sessionId, array $episode): void;

    /**
     * Proposes a new semantic fact for validation.
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
    ): void;

    /**
     * Proposes a new procedure for the Learner's validation pipeline.
     *
     * @param  int           $tenantId
     * @param  string        $trigger
     * @param  list<string>  $workflow
     * @param  string        $impactLevel 'low'|'high'
     */
    public function proposeProcedure(
        int    $tenantId,
        string $trigger,
        array  $workflow,
        string $impactLevel = 'low',
    ): void;
}
