<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Episodic;

use App\Services\V2\Agent\Kernel\AgentContext;
use App\Services\V2\Agent\Kernel\ExecutionMetrics;
use App\Services\V2\Agent\Kernel\ToolTraceEntry;
use App\Services\V2\Agent\Planning\AlignmentResult;
use App\Services\V2\Agent\Planning\ExpansionTrace;
use App\Services\V2\Agent\Reflection\ReflectionResult;
use DateTimeImmutable;
use InvalidArgumentException;
use Illuminate\Support\Str;

/**
 * Registo histórico imutável de uma execução completa do agente.
 *
 * Um Episode é um facto — não uma interpretação. Responde exclusivamente a:
 * "O que aconteceu nesta execução?"
 *
 * Não contém memoryUpdates, insights, procedure candidates, nem qualquer
 * forma de conhecimento derivado. Esses pertencem ao Learner e ao Tier 2,
 * que os produzem a partir de episódios já persistidos.
 *
 * ─── Fronteira explícita: execução vs cognição ───────────────��───────────────
 *
 * Episode                   Learner / Tier 2
 * ──────────────────────    ───────────────────────────────────
 * O que aconteceu?          O que aprendemos?
 * Imutável após criação     Produz novos artefactos
 * Observacional             Interpretativo
 * Independente da FSM       Independente do Episode
 *
 * ─── Ciclo de vida ───────────────────────────────────────────────────────────
 *
 * 1. Runtime chega a COMPLETE ou HANDOFF/TIMEOUT.
 * 2. Chamador determina o EpisodeOutcome a partir do ReflectionResult e do estado final.
 * 3. Episode::fromExecution() constrói o episódio a partir das fontes canónicas.
 * 4. EpisodeRepository::store() persiste o episódio.
 * 5. Learner lê episódios via EpisodeRepository para análise histórica.
 *
 * ─── Fonte de verdade para cada campo ───────────────────────────────────────
 *
 * metrics      → ReflectionResult::$delta (métricas finais pós-Tier1, não as do AgentContext
 *                que reflectem o estado no início da iteração corrente).
 * alignedGoals → AlignmentResult::$alignedGoalIds
 * traces       → AlignmentResult::$traces
 * revision     → AgentContext::$strategicGoalsRevision
 *
 * ─── ReflectionResult é sempre obrigatório ──────────────────────────────────────
 *
 * Um Episode só pode ser criado a partir de execuções cognitivamente completas
 * — i.e., execuções que passaram por REFLECT e produziram ReflectionResult.
 * Execuções interrompidas antes de reflexão (policy denied, handoff, timeout)
 * não geram Episode.
 *
 * Isto é intencional: o Learner só aprende a partir de ciclos cognitivos completos.
 * O handler de persist_agent_result verifica: se reflectionResult === null,
 * não cria Episode — persiste apenas AgentResult.
 *
 * ─── Relação com agent_strategic_goal_metrics ────────────────────────────────
 *
 * Episodes e agent_strategic_goal_metrics são ortogonais e complementares.
 * Episodes são event sourcing — registam o que aconteceu.
 * agent_strategic_goal_metrics é uma projecção materializada — agrega contadores.
 * O AlignmentCoverageRecorder escreve na projecção; o EpisodeRepository escreve
 * nos episódios. Ambos devem ser chamados no mesmo contexto transaccional.
 *
 * @package App\Services\V2\Agent\Memory\Episodic
 * @author  Eduardo Costa Nkuansambu
 */
final readonly class Episode
{
    /**
     * @param list<string>         $alignedGoals    IDs dos goals estratégicos alinhados com a sessão.
     * @param list<ExpansionTrace> $alignmentTraces Trace completo de expansão por goal avaliado.
     * @param list<ToolTraceEntry> $toolTrace       Sequência de tools executadas neste episódio.
     */
    public function __construct(
        public string            $episodeId,
        public int               $tenantId,
        public string            $sessionId,
        public string            $input,
        public array             $alignedGoals,
        public array             $alignmentTraces,
        public array             $toolTrace,
        public ExecutionMetrics  $metrics,
        public string            $strategicRevision,
        public EpisodeOutcome    $outcome,
        public DateTimeImmutable $createdAt,
    ) {
        if (trim($this->episodeId) === '') {
            throw new InvalidArgumentException('Episode id cannot be empty.');
        }

        if ($this->tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be a positive integer.');
        }

        if (trim($this->sessionId) === '') {
            throw new InvalidArgumentException('Session id cannot be empty.');
        }

        if (trim($this->strategicRevision) === '') {
            throw new InvalidArgumentException('Strategic revision cannot be empty.');
        }

        // Validar que todos os aligned goals são strings não-vazias
        foreach ($this->alignedGoals as $goalId) {
            if (! is_string($goalId) || trim($goalId) === '') {
                throw new InvalidArgumentException(
                    'All aligned goals must be non-empty strings.'
                );
            }
        }

        foreach ($this->alignmentTraces as $trace) {
            if (! $trace instanceof ExpansionTrace) {
                throw new InvalidArgumentException(
                    'All alignment traces must be ExpansionTrace instances.'
                );
            }
        }

        foreach ($this->toolTrace as $entry) {
            if (! $entry instanceof ToolTraceEntry) {
                throw new InvalidArgumentException(
                    'All toolTrace entries must be ToolTraceEntry instances.'
                );
            }
        }
    }

    /**
     * Constrói um Episode a partir das saídas canónicas das fases de execução.
     *
     * ReflectionResult é a fonte de verdade para métricas — contém as métricas
     * finais actualizadas pelo Tier 1, enquanto AgentContext::$executionMetrics
     * reflecte o estado antes da última iteração de reflexão.
     *
     * O EpisodeOutcome não é derivável automaticamente — depende do estado final
     * da FSM, que o chamador (Runtime ou handler de COMPLETE) conhece e passa
     * explicitamente. Isto evita que o Episode conheça a topologia da FSM.
     *
     * ReflectionResult é SEMPRE obrigatório. Se for null, o handler não deve
     * chamar este método — apenas persiste AgentResult, não Episode.
     */
    public static function fromExecution(
        AgentContext     $context,
        AlignmentResult  $alignment,
        ReflectionResult $reflection,
        EpisodeOutcome   $outcome,
        array            $toolTrace = [],
    ): self {
        // Métricas finais: lidas do delta do Tier 1.
        // O Tier 1 garante sempre updatedMetrics — ver ReflectionEngineTier1::updateMetrics().
        // Não existe fallback intencional: ausência de métricas indica bug no Tier 1,
        // não um estado normal que deva ser silenciado.
        $metrics = $reflection->delta->updatedMetrics
            ?? throw new \LogicException(
                'ReflectionResult delta has no updatedMetrics. '
                . 'ReflectionEngineTier1::reflect() must always produce ExecutionMetrics.'
            );

        return new self(
            episodeId:         self::generateId(),
            tenantId:          $context->tenantId,
            sessionId:         $context->sessionId,
            input:             $context->input,
            alignedGoals:      $alignment->alignedGoalIds,
            alignmentTraces:   $alignment->traces,
            toolTrace:         $toolTrace,
            metrics:           $metrics,
            strategicRevision: $context->strategicGoalsRevision,
            outcome:           $outcome,
            createdAt:         new DateTimeImmutable(),
        );
    }

    /**
     * Indica se a execução teve pelo menos um goal estratégico alinhado.
     */
    public function hasStrategicAlignment(): bool
    {
        return ! empty($this->alignedGoals);
    }

    /**
     * Indica se algum alinhamento ocorreu exclusivamente por expansão de sinónimos.
     *
     * Útil para o Learner medir o contributo incremental do SynonymExpander
     * em execuções reais — não apenas em métricas de coverage agregadas.
     */
    public function hasExpansionOnlyAlignment(): bool
    {
        foreach ($this->alignmentTraces as $trace) {
            if ($trace->matchedBySynonymOnly()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'episode_id'         => $this->episodeId,
            'tenant_id'          => $this->tenantId,
            'session_id'         => $this->sessionId,
            'input'              => $this->input,
            'aligned_goals'      => $this->alignedGoals,
            'alignment_traces'   => array_map(
                static fn (ExpansionTrace $t): array => $t->toArray(),
                $this->alignmentTraces,
            ),
            'tool_trace'         => array_map(
                static fn (ToolTraceEntry $t): array => $t->toArray(),
                $this->toolTrace,
            ),
            'metrics'            => $this->metrics->toArray(),
            'strategic_revision' => $this->strategicRevision,
            'outcome'            => $this->outcome->value,
            'created_at'         => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Reconstrói um Episode a partir de um array serializado.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['episode_id', 'tenant_id', 'session_id', 'input', 'metrics', 'strategic_revision', 'outcome', 'created_at'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    sprintf('Episode payload missing required field "%s".', $field)
                );
            }
        }

        // Validar tipos antes de usar
        if (! is_string($data['episode_id']) || trim($data['episode_id']) === '') {
            throw new InvalidArgumentException('episode_id must be a non-empty string.');
        }

        if (! is_int($data['tenant_id']) || $data['tenant_id'] <= 0) {
            throw new InvalidArgumentException('tenant_id must be a positive integer.');
        }

        if (! is_string($data['session_id']) || trim($data['session_id']) === '') {
            throw new InvalidArgumentException('session_id must be a non-empty string.');
        }

        if (! is_string($data['input'])) {
            throw new InvalidArgumentException('input must be a string.');
        }

        if (! is_array($data['metrics'])) {
            throw new InvalidArgumentException('metrics must be an array.');
        }

        if (! is_string($data['strategic_revision']) || trim($data['strategic_revision']) === '') {
            throw new InvalidArgumentException('strategic_revision must be a non-empty string.');
        }

        if (! is_string($data['outcome'])) {
            throw new InvalidArgumentException('outcome must be a string.');
        }

        if (! is_string($data['created_at'])) {
            throw new InvalidArgumentException('created_at must be a string.');
        }

        // Parse e validar traces
        $traces = [];
        foreach ($data['alignment_traces'] ?? [] as $index => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException(
                    sprintf('alignment_traces[%d] must be an array.', $index)
                );
            }
            $traces[] = ExpansionTrace::fromArray($raw);
        }

        // Validar e normalizar aligned_goals
        $alignedGoals = [];
        if (is_array($data['aligned_goals'] ?? null)) {
            foreach ($data['aligned_goals'] as $goalId) {
                if (! is_string($goalId) || trim($goalId) === '') {
                    throw new InvalidArgumentException(
                        'All aligned goals must be non-empty strings.'
                    );
                }
                $alignedGoals[] = $goalId;
            }
        }

        // Parse timestamp
        try {
            $createdAt = new DateTimeImmutable((string) $data['created_at']);
        } catch (\Exception) {
            throw new InvalidArgumentException('Invalid created_at value in Episode payload.');
        }

        // Parse outcome enum
        try {
            $episodeOutcome = EpisodeOutcome::from($data['outcome']);
        } catch (\ValueError) {
            throw new InvalidArgumentException(
                sprintf('outcome "%s" is not a valid EpisodeOutcome.', $data['outcome'])
            );
        }

        // Parse metrics
        try {
            $metrics = ExecutionMetrics::fromArray($data['metrics']);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException(
                'Invalid metrics payload: ' . $e->getMessage(),
                previous: $e,
            );
        }

        // Parse tool_trace — campo opcional para compatibilidade com episódios
        // persistidos antes de P4.1 (sem tool_trace na tabela).
        $toolTrace = [];
        foreach ($data['tool_trace'] ?? [] as $index => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException(
                    sprintf('tool_trace[%d] must be an array.', $index)
                );
            }
            $toolTrace[] = ToolTraceEntry::fromArray($raw);
        }

        return new self(
            episodeId:         $data['episode_id'],
            tenantId:          $data['tenant_id'],
            sessionId:         $data['session_id'],
            input:             $data['input'],
            alignedGoals:      $alignedGoals,
            alignmentTraces:   $traces,
            toolTrace:         $toolTrace,
            metrics:           $metrics,
            strategicRevision: $data['strategic_revision'],
            outcome:           $episodeOutcome,
            createdAt:         $createdAt,
        );
    }

    /**
     * Gera um ID único e ordenável para o episódio via ULID.
     *
     * ULIDs são ordenáveis por timestamp, facilitando queries históricas
     * e indexação temporal sem depender de relógio local.
     */
    private static function generateId(): string
    {
        return 'ep_' . Str::ulid()->toString();
    }
}