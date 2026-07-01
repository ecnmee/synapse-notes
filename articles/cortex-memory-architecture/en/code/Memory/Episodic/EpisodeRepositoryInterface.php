<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Episodic;

/**
 * Contract for the episode repository.
 *
 * Allows consumers (e.g. ExecuteAgentService) to depend on an
 * abstraction instead of the final concrete implementation, needed for
 * testability via mocks.
 *
 * @package App\Services\V2\Agent\Memory\Episodic
 * @author  Eduardo Costa Nkuansambu
 */
interface EpisodeRepositoryInterface
{
    public function store(Episode $episode): void;

    /** @return list<Episode> */
    public function recentForTenant(int $tenantId, int $limit = 50): array;

    /** @return list<Episode> */
    public function forRevision(int $tenantId, string $revision, int $limit = 100): array;

    /**
     * Returns null both for "doesn't exist" and "exists but belongs to
     * another tenant". The caller shouldn't distinguish between the two
     * cases, both are treated as absent.
     */
    public function find(int $tenantId, string $episodeId): ?Episode;
}
