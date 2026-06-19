<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Camada de Episodic Memory do CortexOS.
 *
 * Armazena resumos comprimidos de conversas passadas, pesquisáveis
 * por similaridade semântica via embeddings do serviço Python.
 *
 * O processo de compressão (episódio bruto → resumo → embedding) é
 * executado pelo {@see \App\Jobs\V2\CompressEpisodeJob} como DeferredEffect
 * da transição UPDATE_MEMORY → COMPLETE. Não acontece em tempo real.
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
     * Carrega episódios relevantes para a query actual por similaridade.
     *
     * @param  int    $tenantId
     * @param  string $query    Query do utilizador para busca semântica.
     * @param  int    $limit    Número máximo de episódios a retornar.
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
            Log::warning('[EpisodicMemory] loadRelevant falhou', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return [];
        }
    }

    /**
     * Persiste um episódio bruto para compressão assíncrona.
     *
     * O episódio é guardado com status 'pending_compression'.
     * O {@see \App\Jobs\V2\CompressEpisodeJob} comprime e gera o embedding.
     *
     * @param  int                  $tenantId
     * @param  string               $sessionId
     * @param  array<string, mixed> $episode   Dados brutos: messages, tool_trace, result.
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
            Log::error('[EpisodicMemory] persist falhou', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
            ]);
        }
    }
}
