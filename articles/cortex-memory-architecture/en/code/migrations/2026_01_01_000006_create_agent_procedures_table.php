<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `agent_procedures` table.
 *
 * Activation pipeline: candidate -> scored -> active | pending_approval
 *
 * Note: the 'validated' state was removed from the pipeline, no
 * component ever operated the scored -> validated transition. It was a
 * ghost state that created inconsistency between the documented and the
 * real pipeline.
 *
 * No procedure is activated directly:
 *   impact_level = 'low'  -> activates automatically once metrics are reached.
 *   impact_level = 'high' -> requires human approval (pending_approval status).
 *
 * @package Database\Migrations\V2\Agent
 * @author  Eduardo Costa Nkuansambu
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_procedures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // Identifier for the intent that activates this procedure
            $table->string('trigger', 128)->index();
            // Sequence of tool names (JSON array of strings)
            $table->json('workflow');
            $table->decimal('success_rate', 5, 4)->default(0.0);
            $table->unsignedInteger('sample_size')->default(0);
            // low | high
            $table->string('impact_level', 16)->default('low');
            // candidate | scored | active | pending_approval | deprecated
            $table->string('status', 32)->default('candidate')->index();
            $table->string('version', 8)->default('v2')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'trigger', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_procedures');
    }
};
