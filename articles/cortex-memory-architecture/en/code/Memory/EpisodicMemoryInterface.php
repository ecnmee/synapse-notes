<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

/**
 * Contract for the Episodic Memory layer.
 *
 * Allows {@see MemoryBus} to depend on an abstraction instead of the
 * final concrete implementation, needed for testability via mocks.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
interface EpisodicMemoryInterface
{
    public function loadRelevant(int $tenantId, string $query, int $limit = 3): array;

    public function persist(int $tenantId, string $sessionId, array $episode): void;
}
