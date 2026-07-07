<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Kernel;

use App\Services\V2\Agent\Guard\CompiledGuard;
use App\Services\V2\Agent\Guard\GuardParser;
use App\Services\V2\Agent\Guard\GuardSignals;
use App\Services\V2\Agent\Guard\GuardThresholds;

/**
 * Mapa declarativo de todas as transições válidas da FSM do CortexOS.
 *
 * Cada transição declara:
 *   - as guardas (condições obrigatórias para a transição ocorrer);
 *   - os efeitos críticos (síncronos, dentro da transacção DB — se falharem,
 *     a transição falha e o estado não avança);
 *   - os efeitos diferidos (assíncronos, via queue — se falharem, o runtime
 *     continua e os efeitos ficam pendentes na queue).
 *
 * O {@see GuardParser} é injectado no construtor e valida todas as expressões
 * de guarda em boot-time, antes do sistema aceitar qualquer execução.
 * Uma expressão inválida — por sintaxe ou identificador desconhecido —
 * lança {@see \App\Services\V2\Agent\Exceptions\InvalidGuardExpressionException}
 * imediatamente, impedindo o arranque com um mapa corrompido.
 *
 * ─── Learner ─────────────────────────────────────────────────────────────────
 *
 * O efeito `trigger_learner` foi removido deliberadamente (P3).
 * O Learner é executado pela camada de aplicação
 * ({@see \App\Services\V2\Agent\Application\ExecuteAgentService::learn()})
 * após persistência do Episode — não como efeito diferido da FSM.
 * Motivação: ExecuteAgentService já possui Episode e ReflectionResult
 * disponíveis sem necessidade de lookup posterior.
 *
 * Consultado exclusivamente pelo {@see \App\Services\V2\Agent\ExecutionRuntime}.
 * Nenhum outro componente lê ou modifica este mapa.
 *
 * @package App\Services\V2\Agent\Kernel
 * @author  Eduardo Costa Nkuansambu
 */
final class TransitionMap
{
    /**
     * @var array<string, TransitionDefinition>
     *
     * Chave: "{from_state}→{to_state}"
     */
    private readonly array $definitions;

    public function __construct(
        private readonly GuardParser $guardParser,
    ) {
        $this->definitions = $this->build();
    }

    /**
     * Retorna a definição de uma transição entre dois estados.
     *
     * @param  AgentState $from Estado de origem.
     * @param  AgentState $to   Estado de destino.
     * @return TransitionDefinition|null  Null se a transição não estiver definida.
     */
    public function get(AgentState $from, AgentState $to): ?TransitionDefinition
    {
        return $this->definitions[$this->key($from, $to)] ?? null;
    }

    /**
     * Verifica se uma transição entre dois estados está definida no mapa.
     */
    public function has(AgentState $from, AgentState $to): bool
    {
        return isset($this->definitions[$this->key($from, $to)]);
    }

    /**
     * Retorna todos os estados de destino válidos a partir de um estado.
     *
     * @param  AgentState $from Estado de origem.
     * @return list<AgentState>
     */
    public function validTargets(AgentState $from): array
    {
        $prefix  = $from->value . '→';
        $targets = [];

        foreach (array_keys($this->definitions) as $key) {
            if (str_starts_with($key, $prefix)) {
                $toValue   = substr($key, strlen($prefix));
                $targets[] = AgentState::from($toValue);
            }
        }

        return $targets;
    }

    // ─── Construção do mapa ───────────────────────────────────────────────────

    /**
     * @return array<string, TransitionDefinition>
     */
    private function build(): array
    {
        $map = [];

        $define = function (
            AgentState $from,
            AgentState $to,
            array      $guards,
            array      $critical,
            array      $deferred,
        ) use (&$map): void {
            $compiled = array_map(
                fn(string $expression): CompiledGuard => $this->guardParser->compile($expression),
                $guards,
            );

            $map[$this->key($from, $to)] = new TransitionDefinition(
                from:     $from,
                to:       $to,
                guards:   $compiled,
                critical: $critical,
                deferred: $deferred,
            );
        };

        $define(
            AgentState::PLAN,
            AgentState::POLICY_CHECK,
            guards:   [GuardSignals::PLAN_HAS_SELECTED_TOOL],
            critical: [],
            deferred: [],
        );

        $define(
            AgentState::POLICY_CHECK,
            AgentState::ACT,
            guards:   [GuardSignals::POLICY_PERMITTED],
            critical: [],
            deferred: [],
        );

        $define(
            AgentState::POLICY_CHECK,
            AgentState::COMPLETE,
            guards:   [GuardSignals::POLICY_DENIED],
            critical: [],
            deferred: ['persist_policy_block', 'emit_policy_block_event'],
        );

        $define(
            AgentState::ACT,
            AgentState::OBSERVE,
            guards:   [GuardSignals::TOOL_RESULT_RECEIVED],
            critical: [],
            deferred: ['increment_tool_metrics'],
        );

        $define(
            AgentState::ACT,
            AgentState::HANDOFF_REQUESTED,
            guards:   [
                GuardSignals::TOOL_RESULT_REQUESTS_HANDOFF
                . ' OR '
                . GuardThresholds::CONSECUTIVE_FAILURES . ' >= 2',
            ],
            critical: [],
            deferred: ['start_timeout_timer', 'notify_operators_multichannel'],
        );

        $define(
            AgentState::HANDOFF_REQUESTED,
            AgentState::HANDOFF_ACTIVE,
            guards:   [
                GuardSignals::OPERATOR_ACCEPTED,
                'NOT ' . GuardSignals::TIMER_EXPIRED,
            ],
            critical: ['persist_operator_assignment', 'cancel_timer'],
            deferred: [],
        );

        $define(
            AgentState::HANDOFF_REQUESTED,
            AgentState::HANDOFF_TIMEOUT,
            guards:   [GuardSignals::TIMER_EXPIRED],
            critical: ['log_timeout'],
            deferred: ['emit_no_operator_event'],
        );

        $define(
            AgentState::HANDOFF_TIMEOUT,
            AgentState::PLAN,
            guards:   [],
            critical: ['inject_context_note'],
            deferred: [],
        );

        $define(
            AgentState::HANDOFF_ACTIVE,
            AgentState::PLAN,
            guards:   [GuardSignals::OPERATOR_REPLIED],
            critical: ['inject_operator_response_into_context'],
            deferred: ['update_episode'],
        );

        $define(
            AgentState::HANDOFF_ACTIVE,
            AgentState::COMPLETE,
            guards:   [GuardSignals::OPERATOR_ASSUMED_FULL_CONTROL],
            critical: ['close_agent_execution'],
            deferred: ['persist_episode'],
        );

        $define(
            AgentState::OBSERVE,
            AgentState::REFLECT,
            guards:   [],
            critical: [],
            deferred: [],
        );

        $define(
            AgentState::REFLECT,
            AgentState::UPDATE_MEMORY,
            guards:   [],
            critical: [],
            deferred: ['emit_reflection_delta'],
        );

        $define(
            AgentState::UPDATE_MEMORY,
            AgentState::PLAN,
            guards:   [
                'NOT ' . GuardSignals::GOALS_ALL_RESOLVED,
                'NOT ' . GuardSignals::MAX_ITERATIONS_REACHED,
            ],
            critical: ['persist_memory_updates'],
            deferred: [],
        );

        $define(
            AgentState::UPDATE_MEMORY,
            AgentState::COMPLETE,
            guards:   [
                GuardSignals::GOALS_ALL_RESOLVED
                . ' OR '
                . GuardSignals::MAX_ITERATIONS_REACHED,
            ],
            critical: [],
            deferred: ['trigger_cognitive_maintenance'],
        );

        $define(
            AgentState::PLAN,
            AgentState::COMPLETE,
            // Caminho de timeout cooperativo do CortexAgent.
            // DEVE permanecer sem guardas — o CortexAgent decide o timeout
            // antes de chamar transition(); uma guarda aqui tornaria o timeout
            // dependente de sinais que não são emitidos neste caminho.
            guards:   [],
            critical: [],
            deferred: [],
        );

        $define(
            AgentState::COMPLETE,
            AgentState::CLOSED,
            guards:   [GuardSignals::ALL_CRITICAL_EFFECTS_COMMITTED],
            critical: [],
            deferred: [],
        );

        return $map;
    }

    private function key(AgentState $from, AgentState $to): string
    {
        return $from->value . '→' . $to->value;
    }
}