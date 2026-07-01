<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `agent_policy_observations` table.
 *
 * Operational telemetry produced by
 * {@see \App\Services\V2\Agent\Learner\Policy\PolicyObserver} during the
 * learning cycle. Persistence is managed exclusively by
 * {@see \App\Services\V2\Agent\Memory\Policy\PolicyObservationRepository}
 * through {@see \App\Services\V2\Agent\Memory\MemoryBus::proposePolicy()}.
 *
 * --- Architectural decision ---
 *
 * Policy observations are immutable historical facts, not validatable
 * knowledge. That's why there's no candidate/active pipeline, promotion,
 * confidence threshold, or human approval. The table is append-only.
 *
 * --- Fields ---
 *
 * metadata -> JSON (default '[]'). Additional structured information
 *            dependent on the observation's category. The json type
 *            guarantees format validation and partial indexing on
 *            MySQL 5.7+ / PostgreSQL.
 *
 * --- Indexes ---
 *
 * (tenant_id, created_at) -> recentForTenant
 * (tenant_id, category)   -> forCategory
 * (tenant_id, severity)   -> filtering by severity
 * (episode_id)            -> traceability to the originating episode
 *
 * @package Database\Migrations\V2\Agent
 * @author  Eduardo Costa Nkuansambu
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_policy_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            // Null when the observation is produced outside an Episode cycle
            $table->string('episode_id', 64)->nullable()->index();

            $table->string('category', 100);
            $table->text('details');
            // 'info' | 'warning' | 'critical'
            $table->string('severity', 20)->default('info');
            // JSON, format validation guaranteed by the type; default empty array
            $table->json('metadata')->nullable();

            // No updated_at, records are immutable after insertion
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'category']);
            $table->index(['tenant_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_policy_observations');
    }
};
