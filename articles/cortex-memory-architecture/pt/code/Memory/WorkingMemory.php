<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Camada de Working Memory do CortexOS.
 *
 * Armazena o contexto imediato da sessão: entidades activas, último
 * resultado de tool, goals de sessão em curso e notas de estado.
 *
 * Usa cache com TTL para evitar acumulação de sessões abandonadas.
 * Complementa o {@see \App\Services\Core\AI\EntityMemoryService} V1 —
 * não o substitui; coexiste com ele durante a migração V1→V2.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class WorkingMemory implements WorkingMemoryInterface
{
    private const TTL_SECONDS = 3600;
    private const KEY_PREFIX  = 'v2_working_memory';

    /**
     * Carrega o estado de working memory para a sessão.
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
            Log::warning('[WorkingMemory] load falhou', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Actualiza parcialmente o estado da sessão.
     *
     * Faz merge com o estado existente — não substitui completamente.
     *
     * @param  int                  $tenantId
     * @param  string               $sessionId
     * @param  array<string, mixed> $updates  Pares chave/valor a actualizar.
     */
    public function update(int $tenantId, string $sessionId, array $updates): void
    {
        try {
            $current = $this->load($tenantId, $sessionId);
            $merged  = array_merge($current, $updates);

            Cache::put($this->key($tenantId, $sessionId), $merged, self::TTL_SECONDS);
        } catch (\Exception $e) {
            Log::warning('[WorkingMemory] update falhou', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Limpa o estado da sessão após CLOSED.
     *
     * @param  int    $tenantId
     * @param  string $sessionId
     */
    public function clear(int $tenantId, string $sessionId): void
    {
        try {
            Cache::forget($this->key($tenantId, $sessionId));
        } catch (\Exception $e) {
            Log::warning('[WorkingMemory] clear falhou', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Lê um campo específico do estado da sessão.
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
