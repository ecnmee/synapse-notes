<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cria a tabela `agent_episodes`.
 *
 * Memória episódica bruta com pipeline de compressão assíncrona via serviço Python.
 * Distinta de `agent_domain_episodes` (episódios de domínio imutáveis do CortexOS).
 *
 * ─── Campos ───────────────────────────────────────────────────────────────────
 *
 * raw_data   → dados brutos antes da compressão; apagado após CompressEpisodeJob.
 * summary    → resumo comprimido após processamento assíncrono.
 * embedding  → embedding gerado pelo serviço Python (JSON array de floats).
 * tool_trace → sequência de tool calls do episódio (JSON); usado pelo PatternDetector
 *              para análise de padrões cross-episode. Nullable — episódios
 *              anteriores à introdução deste campo não têm tool_trace;
 *              EpisodeRepository::hydrate() trata null como '[]'.
 *
 * @package Database\Migrations\V2\Agent
 * @author  Eduardo Costa Nkuansambu
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_episodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('session_id')->index();
            $table->string('status', 32)->default('pending_compression')->index();

            // Raw data antes da compressão (apagado após CompressEpisodeJob)
            $table->longText('raw_data')->nullable();

            // Summary comprimido após processamento assíncrono
            $table->text('summary')->nullable();

            // Embedding gerado pelo serviço Python (JSON array de floats)
            $table->longText('embedding')->nullable();

            // Alinhamento estratégico (JSON array de ExpansionTrace)
            $table->json('alignment_traces')->nullable();

            // Sequência de tool calls — usado pelo PatternDetector.
            // Nullable: episódios sem tool_trace são tratados como '[]' no repositório.
            $table->json('tool_trace')->nullable();

            $table->string('version', 8)->default('v2')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_episodes');
    }
};
