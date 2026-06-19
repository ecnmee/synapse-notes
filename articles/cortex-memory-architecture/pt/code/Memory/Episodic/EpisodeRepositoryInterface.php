<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Episodic;

/**
 * Contrato do repositório de episódios.
 *
 * Permite que os consumidores (ex: ExecuteAgentService) dependam de uma
 * abstracção em vez da implementação concreta final — necessário para
 * testabilidade via mock.
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

    public function find(string $episodeId): ?Episode;
}
