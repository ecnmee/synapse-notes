<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cria a tabela `agent_policy_observations`.
 *
 * Telemetria operacional produzida pelo
 * {@see \App\Services\V2\Agent\Learner\Policy\PolicyObserver} durante o ciclo
 * de aprendizagem (P3). Persistência gerida exclusivamente pelo
 * {@see \App\Services\V2\Agent\Memory\Policy\PolicyObservationRepository}
 * através do {@see \App\Services\V2\Agent\Memory\MemoryBus::proposePolicy()}.
 *
 * ─── Decisão arquitectural ────────────────────────────────────────────────────
 *
 * Observações de política são factos históricos imutáveis — não conhecimento
 * validável. Por isso não existe pipeline candidate/active, promoção,
 * confidence threshold nem aprovação humana. A tabela é append-only.
 *
 * ─── PK e tenant_id ────────────────────────────────────────────────────────────
 *
 * PK auto-increment ($table->id()) — alinhado com o padrão usado em todas as
 * tabelas do kernel excepto `agent_executions` (UUID, por ser a raiz da FSM
 * e precisar de um ID atribuído antes do INSERT). Esta tabela é telemetria
 * append-only sem essa necessidade, por isso não há motivo para UUID aqui.
 *
 * tenant_id é unsignedBigInteger, consistente com `agent_reflections` e as
 * restantes tabelas do kernel.
 *
 * ─── Índices ─────────────────────────────────────────────────────────────────
 *
 * Nomes implícitos do Laravel — sem nomes explícitos. Os compostos
 * (tenant_id, created_at) / (tenant_id, category) / (tenant_id, severity)
 * espelham directamente os padrões de query do repositório
 * (recentForTenant, forCategory). episode_id tem índice simples para
 * rastreabilidade ao episódio de origem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_policy_observations', function (Blueprint $table) {
            // ── Identidade ────────────────────────────────────────────────────
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            // ── Rastreabilidade ───────────────────────────────────────────────
            // Null quando a observação é produzida fora de um ciclo com Episode
            // (cenário improvável dado que learn() guarda episode === null,
            // mas mantido nullable por robustez).
            $table->string('episode_id', 64)->nullable()->index();

            // ── Conteúdo ──────────────────────────────────────────────────────
            $table->string('category', 100);
            $table->text('details');
            $table->string('severity', 20)->default('info'); // 'info' | 'warning' | 'critical'
            $table->json('metadata')->default('[]');

            // ── Temporal ─────────────────────────────────────────────────────
            // Sem updated_at — registos são imutáveis após inserção.
            $table->timestamp('created_at');

            // ── Índices compostos ────────────────────────────────────────────
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