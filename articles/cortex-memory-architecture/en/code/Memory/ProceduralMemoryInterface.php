<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

/**
 * Contract for the Procedural Memory layer.
 *
 * Allows {@see MemoryBus} to depend on an abstraction instead of the
 * final concrete implementation, needed for testability via mocks.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
interface ProceduralMemoryInterface
{
    public function loadActive(int $tenantId): array;

    public function propose(
        int    $tenantId,
        string $trigger,
        array  $workflow,
        string $impactLevel = 'low',
    ): void;

    public function bootstrapCandidate(
        int    $tenantId,
        string $trigger,
        array  $workflow,
        float  $successRate,
        int    $sampleSize,
        string $impactLevel = 'low',
    ): void;

    public function recordOutcome(int $tenantId, string $trigger, bool $success): void;

    public function deactivate(int $tenantId, string $trigger): void;
}
