<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CortexOS's Procedural Memory layer.
 *
 * Stores executable workflows learned by the agent over time.
 * Each procedure describes a successful resolution pattern: given a
 * trigger (intent type), which sequence of tools to execute.
 *
 * Structure of an active procedure:
 * {
 *   trigger:      "billing_dispute",
 *   workflow:     ["knowledge_search", "llm_cognition"],
 *   success_rate: 0.91,
 *   sample_size:  47,
 *   impact_level: "low",
 *   status:       "active"
 * }
 *
 * Activation pipeline (Invariant, never activates directly):
 *   candidate -> scored -> active (low impact, automatic via ProceduralPromotionJob)
 *                        -> pending_approval (high impact, manual)
 *
 * Used by {@see \App\Services\V2\Agent\Routing\ProceduralRouter} (Layer 2)
 * for tool selection with no token cost.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class ProceduralMemory implements ProceduralMemoryInterface
{
    /**
     * Minimum success_rate threshold for a procedure to be eligible
     * for automatic activation (impact_level = 'low').
     */
    private const AUTO_ACTIVATE_THRESHOLD = 0.85;

    /**
     * Minimum sample size before a procedure can be activated.
     */
    private const MIN_SAMPLE_SIZE = 20;

    /**
     * Loads all active procedures for the tenant.
     *
     * Returns the format expected by {@see AgentMemorySummary::$procedural},
     * compatible with {@see \App\Services\V2\Agent\Routing\ProceduralRouter}.
     *
     * @param  int $tenantId
     * @return array<int, array{trigger: string, tool: string, workflow: list<string>, success_rate: float}>
     */
    public function loadActive(int $tenantId): array
    {
        try {
            return DB::table('agent_procedures')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->where('version', 'v2')
                ->orderByDesc('success_rate')
                ->get(['trigger', 'workflow', 'success_rate', 'sample_size'])
                ->map(function ($row) {
                    $workflow = is_string($row->workflow)
                        ? json_decode($row->workflow, true)
                        : $row->workflow;

                    return [
                        'trigger'      => $row->trigger,
                        'tool'         => $workflow[0] ?? '',
                        'workflow'     => $workflow ?? [],
                        'success_rate' => (float) $row->success_rate,
                        'sample_size'  => (int) $row->sample_size,
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::warning('[ProceduralMemory] loadActive failed', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return [];
        }
    }

    /**
     * Proposes a new procedure with 'candidate' status.
     *
     * Never activates directly, the validation pipeline is mandatory.
     *
     * @param  int           $tenantId
     * @param  string        $trigger      Identifier for the intent that activates this procedure.
     * @param  list<string>  $workflow     Sequence of tool names to execute.
     * @param  string        $impactLevel  'low'|'high'
     */
    public function propose(
        int    $tenantId,
        string $trigger,
        array  $workflow,
        string $impactLevel = 'low',
    ): void {
        try {
            $existing = DB::table('agent_procedures')
                ->where('tenant_id', $tenantId)
                ->where('trigger', $trigger)
                ->where('version', 'v2')
                ->whereIn('status', ['candidate', 'scored', 'active'])
                ->first();

            if ($existing) {
                return;
            }

            DB::table('agent_procedures')->insert([
                'tenant_id'    => $tenantId,
                'trigger'      => $trigger,
                'workflow'     => json_encode($workflow),
                'success_rate' => 0.0,
                'sample_size'  => 0,
                'impact_level' => $impactLevel,
                'status'       => 'candidate',
                'version'      => 'v2',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('[ProceduralMemory] propose failed', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'trigger'    => $trigger,
            ]);
        }
    }

    /**
     * Records the outcome of a procedure execution.
     *
     * Updates the procedure's success_rate and sample_size.
     * If the procedure reaches the automatic activation criteria
     * (impact_level = 'low', success_rate >= threshold, sample_size >= minimum),
     * it transitions to 'active'. If impact_level = 'high', it transitions
     * to 'pending_approval' for human review.
     *
     * @param  int    $tenantId
     * @param  string $trigger
     * @param  bool   $success   Whether the execution was successful.
     */
    public function recordOutcome(int $tenantId, string $trigger, bool $success): void
    {
        try {
            $procedure = DB::table('agent_procedures')
                ->where('tenant_id', $tenantId)
                ->where('trigger', $trigger)
                ->where('version', 'v2')
                ->whereIn('status', ['candidate', 'scored', 'active'])
                ->first();

            if (! $procedure) {
                return;
            }

            $sampleSize  = (int) $procedure->sample_size + 1;
            $oldRate     = (float) $procedure->success_rate;
            $successRate = round(
                ($oldRate * ($sampleSize - 1) + ($success ? 1.0 : 0.0)) / $sampleSize,
                4
            );

            // candidate -> scored transition once threshold is reached.
            // The scored -> active | pending_approval promotion belongs to
            // ProceduralPromotionJob, it does not happen inline here.
            $newStatus = $procedure->status;

            if ($procedure->status === 'candidate'
                && $sampleSize >= self::MIN_SAMPLE_SIZE
                && $successRate >= self::AUTO_ACTIVATE_THRESHOLD
            ) {
                $newStatus = 'scored';
            }

            $fields = [
                'success_rate' => $successRate,
                'sample_size'  => $sampleSize,
                'status'       => $newStatus,
            ];

            // updated_at only advances on state transitions, preserving the
            // activation date so the HealthMonitor's grace period is correct.
            if ($newStatus !== $procedure->status) {
                $fields['updated_at'] = now();
            }

            DB::table('agent_procedures')
                ->where('id', $procedure->id)
                ->update($fields);

        } catch (\Exception $e) {
            Log::warning('[ProceduralMemory] recordOutcome failed', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'trigger'    => $trigger,
            ]);
        }
    }

    /**
     * Inserts a procedure with historical metrics derived from the
     * PatternDetector.
     *
     * Differs from propose() in two ways:
     *   1. Enters with 'scored' status, historical evidence already validated.
     *   2. Preserves real success_rate and sample_size, doesn't start at zero.
     *
     * The scored -> active promotion belongs to ProceduralPromotionJob,
     * which runs periodically and doesn't depend on future traffic.
     *
     * Idempotent by (tenant_id, trigger, version), if a procedure already
     * exists as active or scored for the trigger, it does not replace it.
     *
     * @param  int           $tenantId
     * @param  string        $trigger
     * @param  list<string>  $workflow
     * @param  float         $successRate  Historical rate calculated by the PatternDetector.
     * @param  int           $sampleSize   Number of observed historical executions.
     * @param  string        $impactLevel  'low'|'high'
     */
    public function bootstrapCandidate(
        int    $tenantId,
        string $trigger,
        array  $workflow,
        float  $successRate,
        int    $sampleSize,
        string $impactLevel = 'low',
    ): void {
        try {
            $existing = DB::table('agent_procedures')
                ->where('tenant_id', $tenantId)
                ->where('trigger', $trigger)
                ->where('version', 'v2')
                ->whereIn('status', ['candidate', 'scored', 'active'])
                ->first();

            if ($existing) {
                return;
            }

            // success_rate and sample_size are initialized to zero.
            // The $successRate and $sampleSize parameters represent
            // historical discovery evidence, they qualify the pattern's
            // existence but should not preload runtime metrics.
            // recordOutcome() accumulates from zero, ensuring the
            // HealthMonitor evaluates exclusively real executions as an
            // active procedure.
            DB::table('agent_procedures')->insert([
                'tenant_id'    => $tenantId,
                'trigger'      => $trigger,
                'workflow'     => json_encode($workflow),
                'success_rate' => 0.0,
                'sample_size'  => 0,
                'impact_level' => $impactLevel,
                'status'       => 'scored',
                'version'      => 'v2',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('[ProceduralMemory] bootstrapCandidate failed', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'trigger'    => $trigger,
            ]);
        }
    }

    /**
     * Deactivates a procedure that degraded below the threshold.
     *
     * Used by the CognitiveMaintenance worker to clean up obsolete procedures.
     *
     * @param  int    $tenantId
     * @param  string $trigger
     */
    public function deactivate(int $tenantId, string $trigger): void
    {
        try {
            DB::table('agent_procedures')
                ->where('tenant_id', $tenantId)
                ->where('trigger', $trigger)
                ->where('version', 'v2')
                ->update([
                    'status'     => 'deprecated',
                    'updated_at' => now(),
                ]);
        } catch (\Exception $e) {
            Log::warning('[ProceduralMemory] deactivate failed', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'trigger'    => $trigger,
            ]);
        }
    }
}
