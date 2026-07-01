<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Health monitor for active procedures.
 *
 * Responsibility: identify 'active' procedures that degraded below the
 * minimum quality threshold and deactivate them via
 * {@see ProceduralMemory::deactivate()}.
 *
 * --- Deactivation criterion ---
 *
 * A procedure is deactivated when:
 *   status = 'active'
 *   AND sample_size >= MIN_SAMPLE_SIZE   (minimum evidence for a decision)
 *   AND success_rate < DEGRADATION_THRESHOLD
 *
 * MIN_SAMPLE_SIZE protects recently activated procedures from being
 * deactivated prematurely by an initial run of failures.
 *
 * --- Relationship with ProceduralMemory::deactivate() ---
 *
 * deactivate() already exists, it marks status='deprecated'.
 * The monitor is the component that decides when to call deactivate(),
 * based on statistical criteria. These are separate responsibilities.
 *
 * --- v1 version (absolute threshold) ---
 *
 * This implementation uses an absolute threshold, it does not detect a
 * trend. A procedure stable at 58% is deactivated the same way as one
 * dropping from 90% to 58%. Trend-based distinction belongs to v2, once
 * a history of snapshots exists in agent_procedure_snapshots.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class ProceduralHealthMonitor
{
    /**
     * Minimum success rate to keep a procedure active.
     * Below this value, the procedure is deactivated.
     */
    private const DEGRADATION_THRESHOLD = 0.60;

    /**
     * Minimum number of executions before evaluating degradation.
     * Protects recently activated procedures from premature deactivation.
     */
    private const MIN_SAMPLE_SIZE = 30;

    /**
     * Number of days after activation during which the procedure is
     * immune to deactivation, allows initial metrics to stabilize.
     */
    private const GRACE_PERIOD_DAYS = 7;

    private const TABLE = 'agent_procedures';

    public function __construct(
        private readonly ProceduralMemoryInterface $proceduralMemory,
    ) {}

    /**
     * Evaluates and deactivates degraded procedures for a tenant.
     *
     * @param  int $tenantId
     * @return int Number of procedures deactivated in this call.
     */
    public function monitor(int $tenantId): int
    {
        $deactivated = 0;

        try {
            $degraded = $this->fetchDegradedProcedures($tenantId);
        } catch (\Exception $e) {
            Log::error('[ProceduralHealthMonitor] Failed to load procedures.', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return 0;
        }

        foreach ($degraded as $procedure) {
            try {
                $this->proceduralMemory->deactivate($tenantId, $procedure->trigger);
                $deactivated++;

                Log::info('[ProceduralHealthMonitor] Procedure deactivated.', [
                    'tenant_id'    => $tenantId,
                    'trigger'      => $procedure->trigger,
                    'success_rate' => $procedure->success_rate,
                    'sample_size'  => $procedure->sample_size,
                ]);
            } catch (\Exception $e) {
                Log::warning('[ProceduralHealthMonitor] Failed to deactivate procedure.', [
                    'error'     => $e->getMessage(),
                    'tenant_id' => $tenantId,
                    'trigger'   => $procedure->trigger,
                ]);
            }
        }

        return $deactivated;
    }

    /**
     * Loads active procedures with success_rate below the threshold.
     *
     * @return list<object{trigger: string, success_rate: float, sample_size: int}>
     */
    private function fetchDegradedProcedures(int $tenantId): array
    {
        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('version', 'v2')
            ->where('sample_size', '>=', self::MIN_SAMPLE_SIZE)
            ->where('success_rate', '<', self::DEGRADATION_THRESHOLD)
            ->where('updated_at', '<', now()->subDays(self::GRACE_PERIOD_DAYS))
            ->get(['trigger', 'success_rate', 'sample_size'])
            ->all();
    }
}
