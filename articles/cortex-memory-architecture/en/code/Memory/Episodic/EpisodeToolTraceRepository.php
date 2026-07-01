<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Episodic;

use Illuminate\Support\Facades\DB;

/**
 * Repository for per-episode tool trace telemetry.
 *
 * Provides aggregated access to the `agent_episode_tool_traces` table
 * for cross-episode pattern analysis by
 * {@see \App\Services\V2\Agent\Learner\Procedural\PatternDetector}.
 *
 * --- Responsibility ---
 *
 * This repository does not write, it's read-only.
 * Writing belongs to EpisodeToolTraceWriter (CortexAgent's persistence layer).
 *
 * --- aggregatedPatterns() ---
 *
 * Groups traces by (trigger, workflow_hash) and computes:
 *   executions   -> COUNT(DISTINCT episode_id) per group
 *   success_rate -> AVG(success) per group
 *
 * Filters by the criteria passed in by the PatternDetector
 * (MIN_EXECUTIONS, MIN_SUCCESS_RATE).
 *
 * --- workflowForHash() ---
 *
 * Retrieves the ordered sequence of tool_names for a specific
 * workflow_hash. Uses ORDER BY position to ensure the correct order of
 * tools.
 *
 * @package App\Services\V2\Agent\Memory\Episodic
 * @author  Eduardo Costa Nkuansambu
 */
final class EpisodeToolTraceRepository
{
    private const TABLE = 'agent_episode_tool_traces';

    /**
     * Aggregates detectable workflow patterns for a tenant.
     *
     * @param  int   $tenantId
     * @param  int   $minExecutions   Minimum distinct executions per pattern.
     * @param  float $minSuccessRate  Minimum success rate per pattern.
     * @return list<array{trigger: string, workflow_hash: string, executions: int, success_rate: float}>
     */
    public function aggregatedPatterns(
        int   $tenantId,
        int   $minExecutions,
        float $minSuccessRate,
    ): array {
        return DB::table(self::TABLE)
            ->select([
                'trigger',
                'workflow_hash',
                DB::raw('COUNT(DISTINCT episode_id) as executions'),
                DB::raw('AVG(success) as success_rate'),
            ])
            ->where('tenant_id', $tenantId)
            ->whereNotNull('trigger')
            ->groupBy('trigger', 'workflow_hash')
            ->havingRaw('COUNT(DISTINCT episode_id) >= ?', [$minExecutions])
            ->havingRaw('AVG(success) >= ?', [$minSuccessRate])
            ->get()
            ->map(fn (object $row): array => [
                'trigger'       => $row->trigger,
                'workflow_hash' => $row->workflow_hash,
                'executions'    => (int) $row->executions,
                'success_rate'  => round((float) $row->success_rate, 4),
            ])
            ->all();
    }

    /**
     * Retrieves the ordered sequence of tools for a workflow_hash.
     *
     * Returns only the distinct tool_names per position, one per
     * position. If the hash doesn't exist for the tenant, returns [].
     *
     * @param  int    $tenantId
     * @param  string $workflowHash  SHA1 of the workflow ("tool1>tool2>tool3").
     * @return list<string>          Tools ordered by position (0-indexed).
     */
    public function workflowForHash(int $tenantId, string $workflowHash): array
    {
        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('workflow_hash', $workflowHash)
            ->select('position', DB::raw('MIN(tool_name) as tool_name'))
            ->groupBy('position')
            ->orderBy('position')
            ->pluck('tool_name')
            ->all();
    }
}
