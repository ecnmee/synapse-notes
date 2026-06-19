<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invariante GOVERNANCE-5: registos nunca são apagados.
 * Revogação marca status = 'superseded' e cria novo registo.
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
            // ID do registo que este substitui (null se original)
            $table->unsignedBigInteger('supersedes_fact_id')->nullable()->index();
            $table->timestamp('validated_at')->nullable();
            $table->string('version', 8)->default('v2')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_semantic_memory');
    }
};
