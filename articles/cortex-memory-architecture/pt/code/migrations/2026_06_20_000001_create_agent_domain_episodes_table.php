<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cria a tabela `agent_domain_episodes`.
 *
 * ─── Separação explícita de `agent_episodes` ─────────────────────────────────
 *
 * agent_episodes        → pipeline de compressão assíncrona (serviço Python)
 *                         escrita: EpisodicMemory::persist()
 *
 * agent_domain_episodes → episódios de domínio imutáveis do CortexOS
 *                         escrita: EpisodeRepository::store()
 *                         leitura: ReflectTier2Job, Learner, observabilidade
 *
 * ─── PK string (ULID) ────────────────────────────────────────────────────────
 *
 * episode_id é um ULID gerado pelo domínio (Episode::generateId()),
 * prefixado com "ep_". ULIDs são ordenáveis por timestamp — queries
 * históricas eficientes sem índice separado em created_at.
 *
 * ─── Campos JSON ─────────────────────────────────────────────────────────────
 *
 * aligned_goals     → list<string> de IDs de goals estratégicos alinhados.
 * alignment_traces  → list<ExpansionTrace::toArray()>.
 * metrics           → ExecutionMetrics::toArray() (iterações, tool_calls, latência).
 *
 * tool_trace não é persistido aqui — fica em agent_episode_tool_traces
 * (EpisodeToolTraceRepository), por volume e para facilitar queries
 * de padrões de tool usage independentes do episódio.
 *
 * ─── Índices ─────────────────────────────────────────────────────────────────
 *
 * idx_domain_eps_tenant_created   → Learner: recentForTenant
 * idx_domain_eps_tenant_revision  → ReflectTier2: forRevision (cross-análise)
 * idx_domain_eps_tenant_session   → debug / observabilidade por sessão
 *
 * @package Database\Migrations\V2\Agent
 * @author  Eduardo Costa Nkuansambu
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_domain_episodes', function (Blueprint $table): void {

            // PK é o ULID gerado pelo domínio — "ep_01JXYZ..."
            $table->string('episode_id', 32)->primary();

            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('session_id');
            $table->text('input')->nullable();

            // Alinhamento estratégico
            $table->json('aligned_goals');
            $table->json('alignment_traces')->nullable();

            // Métricas de execução (ExecutionMetrics::toArray())
            $table->json('metrics');

            // Revisão dos goals estratégicos activa no momento da execução
            $table->string('strategic_revision');

            // EpisodeOutcome::value — completed | failed | partial | handoff | timeout
            $table->string('outcome', 32);

            // Timestamp de compaction pelo EpisodeCompactionJob (P5.3).
            // NULL enquanto não compactado. Após compaction, input e
            // alignment_traces são nulled — os campos de identidade nunca são.
            $table->timestamp('compacted_at')->nullable();

            // Imutável após criação — sem updated_at
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'created_at'], 'idx_domain_eps_tenant_created');
            $table->index(['tenant_id', 'strategic_revision'], 'idx_domain_eps_tenant_revision');
            $table->index(['tenant_id', 'session_id'], 'idx_domain_eps_tenant_session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_domain_episodes');
    }
};
