<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Episodic;

use Illuminate\Support\Facades\DB;

/**
 * Repositório de telemetria de tool traces por episódio.
 *
 * Fornece acesso agregado à tabela `agent_episode_tool_traces` para
 * análise de padrões cross-episode pelo {@see \App\Services\V2\Agent\Learner\Procedural\PatternDetector}.
 *
 * ─── Responsabilidade ────────────────────────────────────────────────────────
 *
 * Este repositório não escreve — é read-only.
 * A escrita pertence ao EpisodeToolTraceWriter (camada de persistência do CortexAgent).
 *
 * ─── aggregatedPatterns() ────────────────────────────────────────────────────
 *
 * Agrupa traces por (trigger, workflow_hash) e calcula:
 *   executions  → COUNT(DISTINCT episode_id) por grupo
 *   success_rate → AVG(success) por grupo
 *
 * Filtra pelos critérios passados pelo PatternDetector (MIN_EXECUTIONS, MIN_SUCCESS_RATE).
 *
 * ─── workflowForHash() ───────────────────────────────────────────────────────
 *
 * Recupera a sequência ordenada de tool_names para um workflow_hash específico.
 * Usa ORDER BY position para garantir a ordem correcta das tools.
 *
 * @package App\Services\V2\Agent\Memory\Episodic
 * @author  Eduardo Costa Nkuansambu
 */
final class EpisodeToolTraceRepository
{
    private const TABLE = 'agent_episode_tool_traces';

    /**
     * Agrega padrões de workflow detectáveis para um tenant.
     *
     * @param  int   $tenantId
     * @param  int   $minExecutions   Mínimo de execuções distintas por padrão.
     * @param  float $minSuccessRate  Taxa mínima de sucesso por padrão.
     * @return list<array{trigger: string, workflow_hash: string, executions: int, success_rate: float}>
     */
    public function aggregatedPatterns(
        int   $tenantId,
        int   $minExecutions,
        float $minSuccessRate,
    ): array {
        return DB::table(self::TABLE)
            ->select([
                'trigger',
                'workflow_hash',
                DB::raw('COUNT(DISTINCT episode_id) as executions'),
                DB::raw('AVG(success) as success_rate'),
            ])
            ->where('tenant_id', $tenantId)
            ->whereNotNull('trigger')
            ->groupBy('trigger', 'workflow_hash')
            ->havingRaw('COUNT(DISTINCT episode_id) >= ?', [$minExecutions])
            ->havingRaw('AVG(success) >= ?', [$minSuccessRate])
            ->get()
            ->map(fn (object $row): array => [
                'trigger'       => $row->trigger,
                'workflow_hash' => $row->workflow_hash,
                'executions'    => (int) $row->executions,
                'success_rate'  => round((float) $row->success_rate, 4),
            ])
            ->all();
    }

    /**
     * Recupera a sequência ordenada de tools para um workflow_hash.
     *
     * Devolve apenas os tool_names distintos por posição — um por posição.
     * Se o hash não existir para o tenant, devolve [].
     *
     * @param  int    $tenantId
     * @param  string $workflowHash  SHA1 do workflow ("tool1>tool2>tool3").
     * @return list<string>          Tools ordenadas por position (0-indexed).
     */
    public function workflowForHash(int $tenantId, string $workflowHash): array
    {
        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('workflow_hash', $workflowHash)
            ->select('position', DB::raw('MIN(tool_name) as tool_name'))
            ->groupBy('position')
            ->orderBy('position')
            ->pluck('tool_name')
            ->all();
    }
}
