<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CortexOS's Working Memory layer.
 *
 * Stores the session's immediate context: active entities, the last
 * tool result, in-progress session goals, and state notes.
 *
 * Uses cache with TTL to avoid accumulating abandoned sessions.
 * Complements {@see \App\Services\Core\AI\EntityMemoryService} V1, does
 * not replace it; coexists with it during the V1->V2 migration.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class WorkingMemory implements WorkingMemoryInterface
{
    private const TTL_SECONDS = 3600;
    private const KEY_PREFIX  = 'v2_working_memory';

    /**
     * Loads the working memory state for the session.
     *
     * @param  int    $tenantId
     * @param  string $sessionId
     * @return array<string, mixed>
     */
    public function load(int $tenantId, string $sessionId): array
    {
        try {
            return Cache::get($this->key($tenantId, $sessionId), []);
        } catch (\Exception $e) {
            Log::warning('[WorkingMemory] load failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Partially updates the session state.
     *
     * Merges with the existing state, does not fully replace it.
     *
     * @param  int                  $tenantId
     * @param  string               $sessionId
     * @param  array<string, mixed> $updates  Key/value pairs to update.
     */
    public function update(int $tenantId, string $sessionId, array $updates): void
    {
        try {
            $current = $this->load($tenantId, $sessionId);
            $merged  = array_merge($current, $updates);

            Cache::put($this->key($tenantId, $sessionId), $merged, self::TTL_SECONDS);
        } catch (\Exception $e) {
            Log::warning('[WorkingMemory] update failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clears the session state after CLOSED.
     *
     * @param  int    $tenantId
     * @param  string $sessionId
     */
    public function clear(int $tenantId, string $sessionId): void
    {
        try {
            Cache::forget($this->key($tenantId, $sessionId));
        } catch (\Exception $e) {
            Log::warning('[WorkingMemory] clear failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Reads a specific field from the session state.
     *
     * @param  int    $tenantId
     * @param  string $sessionId
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get(int $tenantId, string $sessionId, string $key, mixed $default = null): mixed
    {
        $state = $this->load($tenantId, $sessionId);
        return $state[$key] ?? $default;
    }

    private function key(int $tenantId, string $sessionId): string
    {
        return self::KEY_PREFIX . ":{$tenantId}:{$sessionId}";
    }
}
