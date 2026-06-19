<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela de telemetria de execução por episódio.
 *
 * Separada de agent_episodes por responsabilidade:
 *   agent_episodes         → memória episódica (o que aconteceu, cognitivamente)
 *   agent_episode_tool_traces → telemetria operacional (como aconteceu, tecnicamente)
 *
 * ─── Uso pelo PatternDetector ────────────────────────────────────────────────
 *
 * A query central do PatternDetector é:
 *
 *   SELECT trigger, workflow_hash, COUNT(*) AS executions, AVG(success) AS success_rate
 *   FROM agent_episode_tool_traces
 *   WHERE tenant_id = ? AND trigger IS NOT NULL
 *   GROUP BY trigger, workflow_hash
 *   HAVING COUNT(*) >= 20 AND AVG(success) >= 0.85
 *
 * workflow_hash permite agrupar execuções com a mesma sequência ordenada de
 * tools — sem ele seria necessário reconstruir e comparar arrays JSON por linha.
 *
 * ─── workflow_hash ────────────────────────────────────────────────────────────
 *
 * SHA1 de "tool1>tool2>tool3" (tools ordenadas por position, separadas por ">").
 * Calculado pelo EpisodeToolTraceRepository antes da inserção.
 * Permite GROUP BY workflow completo sem deserializar arrays.
 *
 * ─── trigger ─────────────────────────────────────────────────────────────────
 *
 * Intent do goal resolvido neste episódio — derivado de goal->metadata['intent'].
 * Nullable: episódios sem goal resolvido não têm trigger significativo para
 * aprendizagem procedimental.
 *
 * ─── Índices ─────────────────────────────────────────────────────────────────
 *
 * (tenant_id, trigger, workflow_hash) — query principal do PatternDetector
 * (episode_id)                        — lookup por episódio (FK implícita)
 * (tenant_id, created_at)             — janela temporal de análise
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

            // Episódio de origem
            $table->string('episode_id', 64)->index();
            $table->unsignedBigInteger('tenant_id')->index();

            // Intent do goal resolvido — null se nenhum goal foi resolvido
            $table->string('trigger', 128)->nullable()->index();

            // Ordem da tool no workflow (0-indexed)
            $table->unsignedSmallInteger('position');

            // Tool executada
            $table->string('tool_name', 128);

            // SHA1("tool1>tool2>tool3") — agrupa workflows completos
            $table->string('workflow_hash', 40)->index();

            // Resultado desta execução
            $table->boolean('success');
            $table->string('outcome', 64)->nullable();
            $table->unsignedInteger('latency_ms')->default(0);

            $table->timestamp('created_at')->useCurrent()->index();

            // Índice composto para a query do PatternDetector
            $table->index(['tenant_id', 'trigger', 'workflow_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_episode_tool_traces');
    }
};
