<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CortexOS's Episodic Memory layer.
 *
 * Stores compressed summaries of past conversations, searchable by
 * semantic similarity via embeddings from the Python service.
 *
 * The compression process (raw episode -> summary -> embedding) is
 * executed by {@see \App\Jobs\V2\CompressEpisodeJob} as a DeferredEffect
 * of the UPDATE_MEMORY -> COMPLETE transition. It doesn't happen in
 * real time.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class EpisodicMemory implements EpisodicMemoryInterface
{
    public function __construct(
        private readonly string $pythonApiUrl,
    ) {}

    /**
     * Loads episodes relevant to the current query by similarity.
     *
     * @param  int    $tenantId
     * @param  string $query    User query for semantic search.
     * @param  int    $limit    Maximum number of episodes to return.
     * @return array<int, array<string, mixed>>
     */
    public function loadRelevant(int $tenantId, string $query, int $limit = 3): array
    {
        if (empty(trim($query))) {
            return [];
        }

        try {
            $response = Http::timeout(5)->post(
                rtrim($this->pythonApiUrl, '/') . '/knowledge/grounded-search',
                [
                    'tenant_id' => $tenantId,
                    'query'     => $query,
                    'top_k'     => $limit,
                    'source'    => 'episodic',
                ]
            );

            if (! $response->successful()) {
                return [];
            }

            return $response->json('strong_matches', []);

        } catch (\Exception $e) {
            Log::warning('[EpisodicMemory] loadRelevant failed', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return [];
        }
    }

    /**
     * Persists a raw episode for asynchronous compression.
     *
     * The episode is saved with 'pending_compression' status.
     * {@see \App\Jobs\V2\CompressEpisodeJob} compresses it and generates
     * the embedding.
     *
     * @param  int                  $tenantId
     * @param  string               $sessionId
     * @param  array<string, mixed> $episode   Raw data: messages, tool_trace, result.
     */
    public function persist(int $tenantId, string $sessionId, array $episode): void
    {
        try {
            DB::table('agent_episodes')->insert([
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
                'status'     => 'pending_compression',
                'raw_data'   => json_encode($episode),
                'version'    => 'v2',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('[EpisodicMemory] persist failed', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
            ]);
        }
    }
}
