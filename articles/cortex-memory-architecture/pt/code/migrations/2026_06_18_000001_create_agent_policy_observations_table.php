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
 * de aprendizagem. Persistência gerida exclusivamente pelo
 * {@see \App\Services\V2\Agent\Memory\Policy\PolicyObservationRepository}
 * através do {@see \App\Services\V2\Agent\Memory\MemoryBus::proposePolicy()}.
 *
 * ─── Decisão arquitectural ────────────────────────────────────────────────────
 *
 * Observações de política são factos históricos imutáveis — não conhecimento
 * validável. Por isso não existe pipeline candidate/active, promoção,
 * confidence threshold nem aprovação humana. A tabela é append-only.
 *
 * ─── Campos ───────────────────────────────────────────────────────────────────
 *
 * metadata → JSON (default '[]'). Informação estruturada adicional dependente
 *            da categoria da observação. Tipo json garante validação de formato
 *            e indexação parcial no MySQL 5.7+ / PostgreSQL.
 *
 * ─── Índices ─────────────────────────────────────────────────────────────────
 *
 * (tenant_id, created_at) → recentForTenant
 * (tenant_id, category)   → forCategory
 * (tenant_id, severity)   → filtro por severidade
 * (episode_id)            → rastreabilidade ao episódio de origem
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

            // Null quando a observação é produzida fora de um ciclo com Episode
            $table->string('episode_id', 64)->nullable()->index();

            $table->string('category', 100);
            $table->text('details');
            // 'info' | 'warning' | 'critical'
            $table->string('severity', 20)->default('info');
            // JSON — validação de formato garantida pelo tipo; default array vazio
            $table->json('metadata')->nullable();

            // Sem updated_at — registos são imutáveis após inserção
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
