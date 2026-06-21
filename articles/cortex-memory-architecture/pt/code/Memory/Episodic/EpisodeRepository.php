<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Episodic;

use App\Services\V2\Agent\Kernel\ExecutionMetrics;
use App\Services\V2\Agent\Kernel\ToolTraceEntry;
use App\Services\V2\Agent\Planning\ExpansionTrace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repositório de episódios de domínio do CortexOS.
 *
 * Persiste e lê registos da tabela `agent_domain_episodes` — separada
 * intencionalmente da `agent_episodes` (pipeline de compressão/embedding
 * do serviço Python).
 *
 * ─── Duas tabelas, dois propósitos ──────────────────────────────────────────
 *
 * agent_episodes        → pipeline legacy de compressão assíncrona (EpisodicMemory)
 *                         campos: raw_data, summary, embedding, status
 *                         escrita: EpisodicMemory::persist()
 *                         leitura: CompressEpisodeJob, serviço Python
 *
 * agent_domain_episodes → episódios de domínio imutáveis (este repositório)
 *                         campos: todos os campos do VO Episode
 *                         escrita: ExecuteAgentService::persistEpisode()
 *                         leitura: ReflectTier2Job, Learner, observabilidade
 *
 * ─── Isolamento por tenant ───────────────────────────────────────────────────
 *
 * Todos os métodos de leitura filtram por tenant_id.
 * find() devolve null tanto para "não existe" como para "existe mas é de
 * outro tenant" — o caller (ReflectTier2Job) não deve distinguir os dois casos.
 *
 * ─── Serialização ────────────────────────────────────────────────────────────
 *
 * aligned_goals e alignment_traces são serializados como JSON.
 * A hidratação reconstrói os VOs a partir de Episode::fromArray() — a
 * lógica de parsing e validação fica centralizada no VO, não aqui.
 *
 * ─── Sem try/catch ───────────────────────────────────────────────────────────
 *
 * store() não encapsula try/catch — o caller (ExecuteAgentService::persistEpisode)
 * captura e re-lança, porque um episódio não persistido bloqueia o dispatch
 * do Tier 2. Falha aqui é crítica — não deve ser silenciada.
 *
 * Os métodos de leitura (find, recentForTenant, forRevision) encapsulam
 * try/catch e devolvem [] ou null em caso de falha — leituras não críticas.
 *
 * @package App\Services\V2\Agent\Memory\Episodic
 * @author  Eduardo Costa Nkuansambu
 */
final class EpisodeRepository implements EpisodeRepositoryInterface
{
    private const TABLE = 'agent_domain_episodes';

    /**
     * Persiste um Episode de domínio.
     *
     * Não encapsula try/catch — falhas de persistência são críticas
     * e devem propagar-se ao caller (ExecuteAgentService::persistEpisode).
     *
     * @throws \Throwable Se a inserção falhar.
     */
    public function store(Episode $episode): void
    {
        DB::table(self::TABLE)->insert([
            'episode_id'         => $episode->episodeId,
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
            'metrics'            => json_encode($episode->metrics->toArray(), JSON_THROW_ON_ERROR),
            'strategic_revision' => $episode->strategicRevision,
            'outcome'            => $episode->outcome->value,
            'created_at'         => $episode->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Devolve null tanto para "não existe" como para "existe mas é de outro tenant".
     * O caller (ReflectTier2Job) não deve distinguir os dois casos.
     */
    public function find(int $tenantId, string $episodeId): ?Episode
    {
        try {
            $row = DB::table(self::TABLE)
                ->where('episode_id', $episodeId)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($row === null) {
                return null;
            }

            return $this->hydrate((array) $row);

        } catch (\Throwable $e) {
            Log::error('[EpisodeRepository] find falhou', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'episode_id' => $episodeId,
            ]);

            return null;
        }
    }

    /**
     * Devolve os episódios mais recentes de um tenant, ordenados por data desc.
     *
     * @return list<Episode>
     */
    public function recentForTenant(int $tenantId, int $limit = 50): array
    {
        try {
            $rows = DB::table(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            return $rows
                ->map(fn (object $row): Episode => $this->hydrate((array) $row))
                ->values()
                ->all();

        } catch (\Throwable $e) {
            Log::warning('[EpisodeRepository] recentForTenant falhou', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);

            return [];
        }
    }

    /**
     * Devolve episódios de uma revisão estratégica específica.
     *
     * Usado pelo Tier 2 para análise cross-episode dentro de uma revisão
     * de goals estratégicos — garante que a comparação é feita entre
     * episódios com o mesmo conjunto de goals activos.
     *
     * @return list<Episode>
     */
    public function forRevision(int $tenantId, string $revision, int $limit = 100): array
    {
        try {
            $rows = DB::table(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('strategic_revision', $revision)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            return $rows
                ->map(fn (object $row): Episode => $this->hydrate((array) $row))
                ->values()
                ->all();

        } catch (\Throwable $e) {
            Log::warning('[EpisodeRepository] forRevision falhou', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
                'revision'  => $revision,
            ]);

            return [];
        }
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

    /**
     * Reconstrói um Episode a partir de uma linha da base de dados.
     *
     * Delega a validação e parsing ao VO Episode::fromArray() —
     * a lógica de invariantes fica centralizada no domínio, não aqui.
     *
     * @param  array<string, mixed> $row
     * @throws \InvalidArgumentException Se os dados forem inválidos.
     * @throws \JsonException            Se o JSON for inválido.
     */
    private function hydrate(array $row): Episode
    {
        $alignedGoals    = json_decode((string) ($row['aligned_goals'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        $alignmentTraces = json_decode((string) ($row['alignment_traces'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        $metrics         = json_decode((string) ($row['metrics'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

        return Episode::fromArray([
            'episode_id'         => $row['episode_id'],
            'tenant_id'          => (int) $row['tenant_id'],
            'session_id'         => $row['session_id'],
            'input'              => $row['input'],
            'aligned_goals'      => $alignedGoals,
            'alignment_traces'   => $alignmentTraces,
            'tool_trace'         => [],  // Não persistido aqui — ver EpisodeToolTraceRepository.
            'metrics'            => $metrics,
            'strategic_revision' => $row['strategic_revision'],
            'outcome'            => $row['outcome'],
            'created_at'         => $row['created_at'],
        ]);
    }
}
