<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

/**
 * Contrato da camada de Episodic Memory.
 *
 * Permite que o {@see MemoryBus} dependa de uma abstracção em vez da
 * implementação concreta final — necessário para testabilidade via mock.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
interface EpisodicMemoryInterface
{
    public function loadRelevant(int $tenantId, string $query, int $limit = 3): array;

    public function persist(int $tenantId, string $sessionId, array $episode): void;
}
