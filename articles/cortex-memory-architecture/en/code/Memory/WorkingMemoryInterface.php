<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

/**
 * Contract for the Working Memory layer.
 *
 * Allows {@see MemoryBus} to depend on an abstraction instead of the
 * final concrete implementation, needed for testability via mocks.
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
