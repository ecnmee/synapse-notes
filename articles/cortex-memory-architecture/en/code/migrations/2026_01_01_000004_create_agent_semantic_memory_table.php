<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `agent_semantic_memory` table.
 *
 * Immutable per-tenant semantic memory. Invariant GOVERNANCE-5: records
 * are never deleted, revocation marks status = 'superseded' and creates
 * a new record.
 *
 * --- Indexes ---
 *
 * idx_semantic_tenant_entity_status -> needed by SemanticConflictResolver:
 *   WHERE tenant_id = ? AND entity = ? AND status = 'active'
 *   Without this index the query does a full scan as facts accumulate per tenant.
 *
 * @package Database\Migrations\V2\Agent
 * @author  Eduardo Costa Nkuansambu
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_semantic_memory', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('entity', 255)->index();
            $table->text('claim');
            $table->decimal('confidence', 5, 4)->default(0.0);
            // kb_strong_match | llm_inference | operator_confirmed
            $table->string('source', 64);
            // candidate | active | superseded
            $table->string('status', 32)->default('candidate')->index();
            // ID of the record this one replaces (null if original)
            $table->unsignedBigInteger('supersedes_fact_id')->nullable()->index();
            $table->timestamp('validated_at')->nullable();
            $table->string('version', 8)->default('v2')->index();
            $table->timestamps();

            // Composite index for the SemanticConflictResolver
            $table->index(['tenant_id', 'entity', 'status'], 'idx_semantic_tenant_entity_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_semantic_memory');
    }
};
