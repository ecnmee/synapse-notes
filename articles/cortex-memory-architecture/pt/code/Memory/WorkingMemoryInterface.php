<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

/**
 * Contrato da camada de Working Memory.
 *
 * Permite que o {@see MemoryBus} dependa de uma abstracção em vez da
 * implementação concreta final — necessário para testabilidade via mock.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
interface WorkingMemoryInterface
{
    public function load(int $tenantId, string $sessionId): array;

    public function update(int $tenantId, string $sessionId, array $updates): void;

    public function clear(int $tenantId, string $sessionId): void;

    public function get(int $tenantId, string $sessionId, string $key, mixed $default = null): mixed;
}
