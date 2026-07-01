<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `agent_episode_tool_traces` table.
 *
 * Operational execution telemetry per episode.
 * Separated from `agent_episodes` by responsibility:
 *   agent_episodes            -> episodic memory (what happened, cognitively)
 *   agent_episode_tool_traces -> operational telemetry (how it happened, technically)
 *
 * --- Use by the PatternDetector ---
 *
 * Central query:
 *   SELECT trigger, workflow_hash, COUNT(*) AS executions, AVG(success) AS success_rate
 *   FROM agent_episode_tool_traces
 *   WHERE tenant_id = ? AND trigger IS NOT NULL
 *   GROUP BY trigger, workflow_hash
 *   HAVING COUNT(*) >= 20 AND AVG(success) >= 0.85
 *
 * --- workflow_hash ---
 *
 * SHA1 of "tool1>tool2>tool3" (tools ordered by position, separated by ">").
 * Calculated by EpisodeToolTraceRepository before insertion.
 * Allows GROUP BY on the complete workflow without deserializing arrays.
 *
 * --- Indexes ---
 *
 * (tenant_id, trigger, workflow_hash) -> the PatternDetector's main query
 * (episode_id)                        -> per-episode lookup
 * (tenant_id, created_at)             -> analysis time window
 *
 * @package Database\Migrations\V2\Agent
 * @author  Eduardo Costa Nkuansambu
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_episode_tool_traces', function (Blueprint $table): void {
            $table->id();

            // Originating episode
            $table->string('episode_id', 64)->index();
            $table->unsignedBigInteger('tenant_id')->index();

            // Intent of the resolved goal, null if no goal was resolved
            $table->string('trigger', 128)->nullable()->index();

            // Tool's order in the workflow (0-indexed)
            $table->unsignedSmallInteger('position');

            // Tool executed
            $table->string('tool_name', 128);

            // SHA1("tool1>tool2>tool3"), groups complete workflows
            $table->string('workflow_hash', 40)->index();

            // Outcome of this execution
            $table->boolean('success');
            $table->string('outcome', 64)->nullable();
            $table->unsignedInteger('latency_ms')->default(0);

            $table->timestamp('created_at')->useCurrent()->index();

            // Composite index for the PatternDetector's query
            $table->index(['tenant_id', 'trigger', 'workflow_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_episode_tool_traces');
    }
};
