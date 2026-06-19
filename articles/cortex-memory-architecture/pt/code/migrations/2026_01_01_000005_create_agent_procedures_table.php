<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline de activação: candidate → scored → validated → active | pending_approval
 *
 * Nenhum procedimento é activado directamente.
 * impact_level = 'low'  → activa automaticamente quando métricas são atingidas.
 * impact_level = 'high' → requer aprovação humana (status pending_approval).
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
            // Identificador da intenção que activa este procedimento
            $table->string('trigger', 128)->index();
            // Sequência de nomes de tools (JSON array de strings)
            $table->json('workflow');
            $table->decimal('success_rate', 5, 4)->default(0.0);
            $table->unsignedInteger('sample_size')->default(0);
            // low | high
            $table->string('impact_level', 16)->default('low');
            // candidate | scored | validated | active | pending_approval | deprecated
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
