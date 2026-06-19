<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona tool_trace à tabela agent_episodes.
 *
 * ─── P4.1 ─────────────────────────────────────────────────────────────────────
 *
 * Pré-requisito do PatternDetector: sem tool_trace nos episódios, o detector
 * não tem dados para analisar padrões de workflow cross-episode.
 *
 * A coluna é nullable para compatibilidade com episódios já persistidos
 * antes desta migration — episódios antigos não têm tool_trace e não devem
 * ser invalidados. O EpisodeRepository::hydrate() trata null como '[]'.
 *
 * @package Database\Migrations\V2\Agent
 * @author  Eduardo Costa Nkuansambu
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_episodes', function (Blueprint $table): void {
            // Nullable — episódios anteriores a P4.1 não têm tool_trace.
            // EpisodeRepository::hydrate() usa ?? '[]' para compatibilidade.
            $table->json('tool_trace')->nullable()->after('alignment_traces');
        });
    }

    public function down(): void
    {
        Schema::table('agent_episodes', function (Blueprint $table): void {
            $table->dropColumn('tool_trace');
        });
    }
};
