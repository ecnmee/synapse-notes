<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Episodic;

use App\Services\V2\Agent\Planning\ExpansionTrace;
use App\Services\V2\Agent\Kernel\ToolTraceEntry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persistência e leitura de {@see Episode}.
 *
 * Responsabilidade única: armazenar e recuperar episódios da tabela
 * `agent_episodes`. Não interpreta, não agrega, não produz insights,
 * não constrói Episodes — recebe Episodes já construídos e persiste-os.
 *
 * ─── Separação de responsabilidades ─────────────────────────────────────────
 *
 * A construção do Episode pertence ao {@see \App\Services\V2\Agent\Application\ExecuteAgentService},
 * que tem acesso ao AgentExecutionOutcome completo (AlignmentResult, ReflectionResult,
 * AgentContext). O repositório recebe o Episode já construído — não recebe
 * AgentContext nem AgentResult como parâmetros.
 *
 * Este repositório é escrita + leitura simples. Queries analíticas complexas
 * (ex: "coverage por revisão", "taxa de sucesso por goal") pertencem ao Learner,
 * que lê episódios e produz as suas próprias projecções.
 *
 * ─── Transacionalidade e consistência ────────────────────────────────────────
 *
 * O EpisodeRepository participa na mesma transação que AgentResultRepository
 * quando ambos existirem. O handler de persist_agent_result controla a transação:
 *
 *   DB::transaction(function () use (...) {
 *       $agentResultRepository->store(...);
 *       $episodeRepository->store(...);
 *   });
 *
 * Se qualquer operação falhar:
 *   - rollback completo
 *   - nenhum AgentResult sem Episode correspondente
 *   - nenhum Episode orfanho
 *
 * O repositório NÃO encapsula try/catch — deixa exceções propagarem
 * para que o handler transacional as trate.
 *
 * ─── AlignmentCoverageRecorder é independente ────────────────────────────────
 *
 * O AlignmentCoverageRecorder escreve em tabelas de projecção (agent_strategic_goal_metrics).
 * É fire-and-forget: falhas não bloqueiam a persistência de Episode + AgentResult.
 * Executado em contexto transacional separado ou ignorado se falhar.
 *
 * ─── Schema esperado (agent_episodes) ────────────────────────────────────────
 *
 * id                  VARCHAR(64)   PK
 * tenant_id           INT           NOT NULL, INDEX
 * session_id          VARCHAR(255)  NOT NULL
 * input               TEXT          NOT NULL
 * aligned_goals       JSON          NOT NULL DEFAULT '[]'
 * alignment_traces    JSON          NOT NULL DEFAULT '[]'
 * metrics             JSON          NOT NULL
 * strategic_revision  VARCHAR(64)   NOT NULL
 * outcome             VARCHAR(32)   NOT NULL, INDEX
 * created_at          TIMESTAMP     NOT NULL, INDEX
 *
 * Índices recomendados:
 *   (tenant_id, created_at)         — queries do Learner por tenant ordenadas por tempo
 *   (tenant_id, strategic_revision) — detecção de degradação após mudança de revisão
 *   (tenant_id, outcome)            — análise de taxa de sucesso por tenant
 *
 * @package App\Services\V2\Agent\Memory\Episodic
 * @author  Eduardo Costa Nkuansambu
 */
final class EpisodeRepository implements EpisodeRepositoryInterface
{
    private const TABLE = 'agent_episodes';

    /**
     * Persiste um Episode já construído.
     *
     * Recebe um Episode completo — não constrói Episodes internamente.
     * A construção é responsabilidade do caller (ExecuteAgentService).
     *
     * Não encapsula try/catch — deixa exceções propagarem para o handler
     * transacional, garantindo atomicidade quando participar numa transação
     * conjunta com AgentResultRepository.
     */
    public function store(Episode $episode): void
    {
        DB::table(self::TABLE)->insert([
            'id'                 => $episode->episodeId,
            'tenant_id'          => $episode->tenantId,
            'session_id'         => $episode->sessionId,
            'input'              => $episode->input,
            'aligned_goals'      => json_encode($episode->alignedGoals, JSON_THROW_ON_ERROR),
            'alignment_traces'   => json_encode(
                array_map(
                    static fn (ExpansionTrace $t): array => $t->toArray(),
                    $episode->alignmentTraces,
                ),
                JSON_THROW_ON_ERROR,
            ),
            'tool_trace'         => json_encode(
                array_map(
                    static fn (ToolTraceEntry $t): array => $t->toArray(),
                    $episode->toolTrace,
                ),
                JSON_THROW_ON_ERROR,
            ),
            'metrics'            => json_encode($episode->metrics->toArray(), JSON_THROW_ON_ERROR),
            'strategic_revision' => $episode->strategicRevision,
            'outcome'            => $episode->outcome->value,
            'created_at'         => $episode->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Carrega os N episódios mais recentes de um tenant.
     *
     * @param  int $tenantId
     * @param  int $limit    Máximo de episódios a devolver (default: 50).
     * @return list<Episode>
     *
     * @throws InvalidArgumentException Se $limit <= 0.
     */
    public function recentForTenant(int $tenantId, int $limit = 50): array
    {
        if ($limit <= 0) {
            throw new InvalidArgumentException('Limit must be greater than zero.');
        }

        $rows = DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => $this->hydrate($row))->values()->all();
    }

    /**
     * Carrega episódios de um tenant filtrados por revisão estratégica.
     *
     * Útil para o Learner comparar comportamento antes e depois de uma
     * mudança nos goals estratégicos do tenant.
     *
     * @param  int    $tenantId
     * @param  string $revision
     * @param  int    $limit
     * @return list<Episode>
     *
     * @throws InvalidArgumentException Se $limit <= 0.
     */
    public function forRevision(int $tenantId, string $revision, int $limit = 100): array
    {
        if ($limit <= 0) {
            throw new InvalidArgumentException('Limit must be greater than zero.');
        }

        $rows = DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('strategic_revision', $revision)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => $this->hydrate($row))->values()->all();
    }

    /**
     * Carrega um episódio pelo ID.
     *
     * Usado pelo {@see \App\Services\V2\Agent\Jobs\ReflectTier2Job} para
     * carregar o Episode antes de invocar o ReflectionEngineTier2.
     *
     * Devolve null se o episódio não existir.
     */
    public function find(string $episodeId): ?Episode
    {
        $row = DB::table(self::TABLE)
            ->where('id', $episodeId)
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * Reconstrói um Episode a partir de uma linha da base de dados.
     *
     * Usa JSON_THROW_ON_ERROR para detectar corrupção de dados imediatamente.
     *
     * @throws \JsonException Se alguma coluna JSON estiver corrompida.
     */
    private function hydrate(object $row): Episode
    {
        return Episode::fromArray([
            'episode_id'         => $row->id,
            'tenant_id'          => $row->tenant_id,
            'session_id'         => $row->session_id,
            'input'              => $row->input,
            'aligned_goals'      => json_decode(
                $row->aligned_goals,
                associative: true,
                flags: \JSON_THROW_ON_ERROR,
            ),
            'alignment_traces'   => json_decode(
                $row->alignment_traces,
                associative: true,
                flags: \JSON_THROW_ON_ERROR,
            ),
            'tool_trace'         => json_decode(
                $row->tool_trace ?? '[]',
                associative: true,
                flags: \JSON_THROW_ON_ERROR,
            ),
            'metrics'            => json_decode(
                $row->metrics,
                associative: true,
                flags: \JSON_THROW_ON_ERROR,
            ),
            'strategic_revision' => $row->strategic_revision,
            'outcome'            => $row->outcome,
            'created_at'         => $row->created_at,
        ]);
    }
}