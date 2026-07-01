<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Policy;

use Illuminate\Support\Facades\DB;

/**
 * Repository for policy observations produced by
 * {@see \App\Services\V2\Agent\Learner\Policy\PolicyObserver}.
 *
 * Single responsibility: persist {@see PolicyObservation} as operational
 * telemetry. There's no candidate/active pipeline, promotion,
 * consolidation, or reinforcement, policy observations are immutable
 * historical facts, not validatable knowledge.
 *
 * --- Separation of responsibilities ---
 *
 * Construction and evaluation of observations belongs to
 * {@see \App\Services\V2\Agent\Learner\Policy\PolicyObserver}.
 * This repository receives already-built observations and only persists
 * them.
 *
 * External access always goes through
 * {@see \App\Services\V2\Agent\Memory\MemoryBus} via
 * {@see MemoryBus::proposePolicy()}, no application-layer component
 * calls this repository directly.
 *
 * --- Expected schema (agent_policy_observations) ---
 *
 * id           BIGINT UNSIGNED  PK AUTO_INCREMENT
 * tenant_id    BIGINT UNSIGNED  NOT NULL, INDEX
 * episode_id   VARCHAR(64)      NULL, INDEX     - originating episode (if available)
 * category     VARCHAR(100)     NOT NULL, INDEX
 * details      TEXT             NOT NULL
 * severity     VARCHAR(20)      NOT NULL        - 'info' | 'warning' | 'critical'
 * metadata     JSON             NOT NULL DEFAULT '[]'
 * created_at   TIMESTAMP        NOT NULL, INDEX
 *
 * Recommended indexes:
 *   (tenant_id, created_at)   - per-tenant telemetry queries ordered by time
 *   (tenant_id, category)     - pattern analysis by category
 *   (tenant_id, severity)     - filtering by urgency
 *
 * @package App\Services\V2\Agent\Memory\Policy
 * @author  Eduardo Costa Nkuansambu
 */
final class PolicyObservationRepository
{
    private const TABLE = 'agent_policy_observations';

    /**
     * Persists a policy observation.
     *
     * Takes scalars, doesn't depend on the
     * {@see \App\Services\V2\Agent\Learner\Policy\PolicyObservation} VO.
     * Decomposition is done by the caller
     * ({@see \App\Services\V2\Agent\Memory\MemoryBus::proposePolicy()}).
     *
     * Doesn't assign `id` manually, the column is auto-increment
     * (aligned with the pattern used across every kernel table except
     * `agent_executions`, which uses a UUID since it's the FSM's root).
     *
     * Doesn't wrap itself in try/catch, the caller is responsible for
     * handling failures and ensuring an exception here doesn't block
     * the main flow.
     *
     * @param int                  $tenantId  Originating tenant.
     * @param string               $category  Observation type (e.g. "failure_recovery").
     * @param string               $message   Observation description.
     * @param string               $severity  Level: 'info' | 'warning' | 'critical'.
     * @param string|null          $episodeId Originating episode, when available.
     * @param array<string, mixed> $metadata  Additional structured evidence.
     */
    public function store(
        int     $tenantId,
        string  $category,
        string  $message,
        string  $severity  = 'info',
        ?string $episodeId = null,
        array   $metadata  = [],
    ): void {
        DB::table(self::TABLE)->insert([
            'tenant_id'  => $tenantId,
            'episode_id' => $episodeId,
            'category'   => $category,
            'details'    => $message,
            'severity'   => $severity,
            'metadata'   => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Loads the N most recent observations for a tenant.
     *
     * Used by telemetry and audit dashboards.
     * Not used on the critical execution path.
     *
     * @param  int $tenantId
     * @param  int $limit    Maximum number of observations (default: 100).
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException If $limit <= 0.
     */
    public function recentForTenant(int $tenantId, int $limit = 100): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Limit must be greater than zero.');
        }

        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): array => [
                'id'         => $row->id,
                'tenant_id'  => $row->tenant_id,
                'episode_id' => $row->episode_id,
                'category'   => $row->category,
                'details'    => $row->details,
                'severity'   => $row->severity,
                'metadata'   => json_decode($row->metadata, associative: true, flags: \JSON_THROW_ON_ERROR),
                'created_at' => $row->created_at,
            ])
            ->values()
            ->all();
    }

    /**
     * Loads a tenant's observations filtered by category.
     *
     * Useful for analyzing recurring patterns within a tenant.
     *
     * @param  int    $tenantId
     * @param  string $category
     * @param  int    $limit
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException If $limit <= 0.
     */
    public function forCategory(int $tenantId, string $category, int $limit = 50): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Limit must be greater than zero.');
        }

        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('category', $category)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): array => [
                'id'         => $row->id,
                'tenant_id'  => $row->tenant_id,
                'episode_id' => $row->episode_id,
                'category'   => $row->category,
                'details'    => $row->details,
                'severity'   => $row->severity,
                'metadata'   => json_decode($row->metadata, associative: true, flags: \JSON_THROW_ON_ERROR),
                'created_at' => $row->created_at,
            ])
            ->values()
            ->all();
    }
}
