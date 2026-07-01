<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Episodic;

use App\Services\V2\Agent\Kernel\ExecutionMetrics;
use App\Services\V2\Agent\Kernel\ToolTraceEntry;
use App\Services\V2\Agent\Planning\ExpansionTrace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repository for CortexOS's domain episodes.
 *
 * Persists and reads records from the `agent_domain_episodes` table,
 * intentionally separate from `agent_episodes` (the Python service's
 * compression/embedding pipeline).
 *
 * --- Two tables, two purposes ---
 *
 * agent_episodes         -> legacy async compression pipeline (EpisodicMemory)
 *                          fields: raw_data, summary, embedding, status
 *                          write: EpisodicMemory::persist()
 *                          read: CompressEpisodeJob, the Python service
 *
 * agent_domain_episodes  -> immutable domain episodes (this repository)
 *                          fields: all fields of the Episode VO
 *                          write: ExecuteAgentService::persistEpisode()
 *                          read: ReflectTier2Job, Learner, observability
 *
 * --- Tenant isolation ---
 *
 * All read methods filter by tenant_id.
 * find() returns null both for "doesn't exist" and "exists but belongs
 * to another tenant", the caller (ReflectTier2Job) shouldn't distinguish
 * between the two cases.
 *
 * --- Serialization ---
 *
 * aligned_goals and alignment_traces are serialized as JSON.
 * Hydration rebuilds the VOs via Episode::fromArray(), parsing and
 * validation logic stays centralized in the VO, not here.
 *
 * --- No try/catch ---
 *
 * store() doesn't wrap itself in try/catch, the caller
 * (ExecuteAgentService::persistEpisode) catches and re-throws, because
 * an unpersisted episode blocks the Tier 2 dispatch. Failure here is
 * critical, it shouldn't be silenced.
 *
 * The read methods (find, recentForTenant, forRevision) wrap themselves
 * in try/catch and return [] or null on failure, non-critical reads.
 *
 * @package App\Services\V2\Agent\Memory\Episodic
 * @author  Eduardo Costa Nkuansambu
 */
final class EpisodeRepository implements EpisodeRepositoryInterface
{
    private const TABLE = 'agent_domain_episodes';

    /**
     * Persists a domain Episode.
     *
     * Doesn't wrap itself in try/catch, persistence failures are
     * critical and must propagate to the caller
     * (ExecuteAgentService::persistEpisode).
     *
     * @throws \Throwable If the insert fails.
     */
    public function store(Episode $episode): void
    {
        DB::table(self::TABLE)->insert([
            'episode_id'         => $episode->episodeId,
            'tenant_id'          => $episode->tenantId,
            'session_id'         => $episode->sessionId,
            'input'              => $episode->input,
            'aligned_goals'      => json_encode($episode->alignedGoals, JSON_THROW_ON_ERROR),
            'alignment_traces'   => json_encode(
                array_map(
                    static fn (ExpansionTrace $t): array => $t->toArray(),
                    $episode->alignmentTraces,
                ),
                JSON_THROW_ON_ERROR,
            ),
            'metrics'            => json_encode($episode->metrics->toArray(), JSON_THROW_ON_ERROR),
            'strategic_revision' => $episode->strategicRevision,
            'outcome'            => $episode->outcome->value,
            'created_at'         => $episode->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Returns null both for "doesn't exist" and "exists but belongs to
     * another tenant". The caller (ReflectTier2Job) shouldn't
     * distinguish between the two cases.
     */
    public function find(int $tenantId, string $episodeId): ?Episode
    {
        try {
            $row = DB::table(self::TABLE)
                ->where('episode_id', $episodeId)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($row === null) {
                return null;
            }

            return $this->hydrate((array) $row);

        } catch (\Throwable $e) {
            Log::error('[EpisodeRepository] find failed', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'episode_id' => $episodeId,
            ]);

            return null;
        }
    }

    /**
     * Returns a tenant's most recent episodes, sorted by date descending.
     *
     * @return list<Episode>
     */
    public function recentForTenant(int $tenantId, int $limit = 50): array
    {
        try {
            $rows = DB::table(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            return $rows
                ->map(fn (object $row): Episode => $this->hydrate((array) $row))
                ->values()
                ->all();

        } catch (\Throwable $e) {
            Log::warning('[EpisodeRepository] recentForTenant failed', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);

            return [];
        }
    }

    /**
     * Returns episodes from a specific strategic revision.
     *
     * Used by Tier 2 for cross-episode analysis within a strategic
     * goals revision, ensures the comparison happens between episodes
     * with the same set of active goals.
     *
     * @return list<Episode>
     */
    public function forRevision(int $tenantId, string $revision, int $limit = 100): array
    {
        try {
            $rows = DB::table(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('strategic_revision', $revision)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            return $rows
                ->map(fn (object $row): Episode => $this->hydrate((array) $row))
                ->values()
                ->all();

        } catch (\Throwable $e) {
            Log::warning('[EpisodeRepository] forRevision failed', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
                'revision'  => $revision,
            ]);

            return [];
        }
    }

    // --- Private ---

    /**
     * Rebuilds an Episode from a database row.
     *
     * Delegates validation and parsing to the Episode::fromArray() VO,
     * invariant logic stays centralized in the domain, not here.
     *
     * @param  array<string, mixed> $row
     * @throws \InvalidArgumentException If the data is invalid.
     * @throws \JsonException            If the JSON is invalid.
     */
    private function hydrate(array $row): Episode
    {
        $alignedGoals    = json_decode((string) ($row['aligned_goals'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        $alignmentTraces = json_decode((string) ($row['alignment_traces'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        $metrics         = json_decode((string) ($row['metrics'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

        return Episode::fromArray([
            'episode_id'         => $row['episode_id'],
            'tenant_id'          => (int) $row['tenant_id'],
            'session_id'         => $row['session_id'],
            'input'              => $row['input'],
            'aligned_goals'      => $alignedGoals,
            'alignment_traces'   => $alignmentTraces,
            'tool_trace'         => [],  // Not persisted here, see EpisodeToolTraceRepository.
            'metrics'            => $metrics,
            'strategic_revision' => $row['strategic_revision'],
            'outcome'            => $row['outcome'],
            'created_at'         => $row['created_at'],
        ]);
    }
}
