<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Policy;

use Illuminate\Support\Facades\DB;

/**
 * Repositório de observações de política produzidas pelo {@see \App\Services\V2\Agent\Learner\Policy\PolicyObserver}.
 *
 * Responsabilidade única: persistir {@see PolicyObservation} como telemetria
 * operacional. Não existe pipeline candidate/active, promoção, consolidação
 * nem reinforcement — observações de política são factos históricos imutáveis,
 * não conhecimento validável.
 *
 * ─── Separação de responsabilidades ─────────────────────────────────────────
 *
 * A construção e a avaliação das observações pertencem ao
 * {@see \App\Services\V2\Agent\Learner\Policy\PolicyObserver}.
 * Este repositório recebe observações já construídas e apenas as persiste.
 *
 * O acesso externo passa sempre pelo {@see \App\Services\V2\Agent\Memory\MemoryBus}
 * via {@see MemoryBus::proposePolicy()} — nenhum componente da camada de
 * aplicação chama este repositório directamente.
 *
 * ─── Schema esperado (agent_policy_observations) ─────────────────────────────
 *
 * id           BIGINT UNSIGNED  PK AUTO_INCREMENT
 * tenant_id    BIGINT UNSIGNED  NOT NULL, INDEX
 * episode_id   VARCHAR(64)      NULL, INDEX     — episódio de origem (se disponível)
 * category     VARCHAR(100)     NOT NULL, INDEX
 * details      TEXT             NOT NULL
 * severity     VARCHAR(20)      NOT NULL        — 'info' | 'warning' | 'critical'
 * metadata     JSON             NOT NULL DEFAULT '[]'
 * created_at   TIMESTAMP        NOT NULL, INDEX
 *
 * Índices recomendados:
 *   (tenant_id, created_at)   — queries de telemetria por tenant ordenadas por tempo
 *   (tenant_id, category)     — análise de padrões por categoria
 *   (tenant_id, severity)     — filtragem por urgência
 *
 * @package App\Services\V2\Agent\Memory\Policy
 * @author  Eduardo Costa Nkuansambu
 */
final class PolicyObservationRepository
{
    private const TABLE = 'agent_policy_observations';

    /**
     * Persiste uma observação de política.
     *
     * Recebe escalares — não depende do VO {@see \App\Services\V2\Agent\Learner\Policy\PolicyObservation}.
     * A decomposição é feita pelo caller ({@see \App\Services\V2\Agent\Memory\MemoryBus::proposePolicy()}).
     *
     * Não atribui `id` manualmente — a coluna é auto-increment
     * (alinhado com o padrão usado em todas as tabelas do kernel excepto
     * `agent_executions`, que usa UUID por ser a raiz da FSM).
     *
     * Não encapsula try/catch — o caller é responsável por tratar falhas e
     * garantir que uma excepção aqui não bloqueia o fluxo principal.
     *
     * @param int                  $tenantId  Tenant de origem.
     * @param string               $category  Tipo de observação (ex: "failure_recovery").
     * @param string               $message   Descrição da observação.
     * @param string               $severity  Nível: 'info' | 'warning' | 'critical'.
     * @param string|null          $episodeId Episódio de origem, quando disponível.
     * @param array<string, mixed> $metadata  Evidências adicionais estruturadas.
     */
    public function store(
        int     $tenantId,
        string  $category,
        string  $message,
        string  $severity  = 'info',
        ?string $episodeId = null,
        array   $metadata  = [],
    ): void {
        DB::table(self::TABLE)->insert([
            'tenant_id'  => $tenantId,
            'episode_id' => $episodeId,
            'category'   => $category,
            'details'    => $message,
            'severity'   => $severity,
            'metadata'   => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Carrega as N observações mais recentes de um tenant.
     *
     * Usado por dashboards de telemetria e auditoria.
     * Não é usado no caminho crítico de execução.
     *
     * @param  int $tenantId
     * @param  int $limit    Máximo de observações (default: 100).
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException Se $limit <= 0.
     */
    public function recentForTenant(int $tenantId, int $limit = 100): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Limit must be greater than zero.');
        }

        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): array => [
                'id'         => $row->id,
                'tenant_id'  => $row->tenant_id,
                'episode_id' => $row->episode_id,
                'category'   => $row->category,
                'details'    => $row->details,
                'severity'   => $row->severity,
                'metadata'   => json_decode($row->metadata, associative: true, flags: \JSON_THROW_ON_ERROR),
                'created_at' => $row->created_at,
            ])
            ->values()
            ->all();
    }

    /**
     * Carrega observações de um tenant filtradas por categoria.
     *
     * Útil para análise de padrões recorrentes num tenant.
     *
     * @param  int    $tenantId
     * @param  string $category
     * @param  int    $limit
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException Se $limit <= 0.
     */
    public function forCategory(int $tenantId, string $category, int $limit = 50): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Limit must be greater than zero.');
        }

        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('category', $category)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): array => [
                'id'         => $row->id,
                'tenant_id'  => $row->tenant_id,
                'episode_id' => $row->episode_id,
                'category'   => $row->category,
                'details'    => $row->details,
                'severity'   => $row->severity,
                'metadata'   => json_decode($row->metadata, associative: true, flags: \JSON_THROW_ON_ERROR),
                'created_at' => $row->created_at,
            ])
            ->values()
            ->all();
    }
}