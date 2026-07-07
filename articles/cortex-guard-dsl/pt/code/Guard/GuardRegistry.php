<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Guard;

use App\Services\V2\Agent\Exceptions\InvalidGuardExpressionException;

/**
 * Mapa explícito de avaliação de guardas atómicas.
 *
 * Canais de leitura por tipo de sinal:
 *   - Flags transitórias   → {@see RuntimeSignals}
 *   - Contadores históricos → {@see \App\Services\V2\Agent\Kernel\ExecutionMetrics}
 *   - Estado cognitivo      → {@see \App\Services\V2\Agent\Kernel\AgentContext}
 *
 * O {@see GuardParser} usa {@see self::existsSignal()} e {@see self::existsThreshold()}
 * em boot-time para validar identificadores sem precisar de contexto real.
 * A separação impede que um sinal booleano seja aceite em posição de threshold
 * (e.g. `operator.accepted >= 2`), o que causaria InvalidGuardExpressionException
 * em runtime mesmo após validação boot-time bem-sucedida.
 *
 * Os `default` dos `match` internos lançam {@see \LogicException} e não
 * {@see InvalidGuardExpressionException}: se o parser validou a expressão em
 * boot-time, um identificador desconhecido aqui é sempre um bug interno,
 * não uma expressão inválida fornecida externamente.
 *
 * @package App\Services\V2\Agent\Guard
 * @author  Eduardo Costa Nkuansambu
 */
final class GuardRegistry
{
    /**
     * Identificadores atómicos reconhecidos.
     *
     * @var list<string>
     */
    private const KNOWN_SIGNALS = [
        GuardSignals::POLICY_PERMITTED,
        GuardSignals::POLICY_DENIED,
        GuardSignals::PLAN_HAS_SELECTED_TOOL,
        GuardSignals::TOOL_RESULT_RECEIVED,
        GuardSignals::TOOL_RESULT_REQUESTS_HANDOFF,
        GuardSignals::GOALS_ALL_RESOLVED,
        GuardSignals::OPERATOR_ACCEPTED,
        GuardSignals::OPERATOR_REPLIED,
        GuardSignals::OPERATOR_ASSUMED_FULL_CONTROL,
        GuardSignals::TIMER_EXPIRED,
        GuardSignals::ALL_CRITICAL_EFFECTS_COMMITTED,
        GuardSignals::MAX_ITERATIONS_REACHED,
    ];

    /**
     * Identificadores de threshold reconhecidos (usados com >= N).
     *
     * @var list<string>
     */
    private const KNOWN_THRESHOLDS = [
        GuardThresholds::CONSECUTIVE_FAILURES,
    ];

    /**
     * Verifica se um identificador é um sinal booleano reconhecido.
     *
     * Usado pelo {@see GuardParser} em boot-time para validar átomos simples.
     * Não aceita identificadores de threshold — use {@see self::existsThreshold()}.
     */
    public function existsSignal(string $identifier): bool
    {
        return in_array($identifier, self::KNOWN_SIGNALS, strict: true);
    }

    /**
     * Verifica se um identificador é um threshold reconhecido (usado com >= N).
     *
     * Usado pelo {@see GuardParser} em boot-time para validar átomos com threshold.
     * Não aceita sinais booleanos — use {@see self::existsSignal()}.
     */
    public function existsThreshold(string $identifier): bool
    {
        return in_array($identifier, self::KNOWN_THRESHOLDS, strict: true);
    }

    /**
     * Avalia uma guarda atómica.
     *
     * @throws InvalidGuardExpressionException  Se o identificador não for reconhecido.
     */
    public function evaluate(string $guard, GuardContext $ctx): bool
    {
        if (preg_match('/^(\S+)\s*>=\s*(\d+)$/', $guard, $m)) {
            return $this->evaluateThreshold($m[1], (int) $m[2], $ctx);
        }

        return match ($guard) {
            GuardSignals::POLICY_PERMITTED              => $ctx->signals->policyPermitted,
            GuardSignals::POLICY_DENIED                 => $ctx->signals->policyDenied,
            GuardSignals::PLAN_HAS_SELECTED_TOOL        => $ctx->signals->planHasSelectedTool,
            GuardSignals::TOOL_RESULT_RECEIVED          => $ctx->signals->toolResultReceived,
            GuardSignals::TOOL_RESULT_REQUESTS_HANDOFF  => $ctx->signals->toolResultRequestsHandoff,
            GuardSignals::GOALS_ALL_RESOLVED            => $this->goalsAllResolved($ctx),
            GuardSignals::OPERATOR_ACCEPTED             => $ctx->signals->operatorAccepted,
            GuardSignals::OPERATOR_REPLIED              => $ctx->signals->operatorReplied,
            GuardSignals::OPERATOR_ASSUMED_FULL_CONTROL => $ctx->signals->operatorAssumedFullControl,
            GuardSignals::TIMER_EXPIRED                 => $ctx->signals->timerExpired,
            GuardSignals::ALL_CRITICAL_EFFECTS_COMMITTED => $ctx->signals->allCriticalEffectsCommitted,
            GuardSignals::MAX_ITERATIONS_REACHED        => $this->maxIterationsReached($ctx),
            default => throw new \LogicException(
                "Guarda reconhecida pelo parser mas não tratada pelo registry: [{$guard}]. Isto é um bug interno."
            ),
        };
    }

    /**
     * Avalia guardas com threshold numérico: "identifier >= N".
     *
     * @throws InvalidGuardExpressionException
     */
    private function evaluateThreshold(string $identifier, int $threshold, GuardContext $ctx): bool
    {
        return match ($identifier) {
            GuardThresholds::CONSECUTIVE_FAILURES => $ctx->metrics->consecutiveFailures >= $threshold,
            default => throw new \LogicException(
                "Threshold reconhecido pelo parser mas não tratado pelo registry: [{$identifier}]. Isto é um bug interno."
            ),
        };
    }

    /**
     * Verifica se o número máximo de iterações foi atingido.
     *
     * O limite é fixo em 10 iterações. Se necessário no futuro, pode ser
     * movido para AgentPolicies ou AgentPersonaSnapshot.
     */
    private function maxIterationsReached(GuardContext $ctx): bool
    {
        return $ctx->metrics->iterationCount >= 10;
    }

    /**
     * Verifica se todos os goals activos estão resolvidos.
     *
     * Fonte de verdade: {@see AgentContext::$activeGoals}.
     * O {@see ReflectionEngineTier1} substitui a lista de goals activos
     * pelos goals remanescentes a cada iteração. Quando a lista fica vazia,
     * todos os goals foram resolvidos.
     *
     * O sinal explícito em RuntimeSignals tem precedência — permite ao estado
     * FSM declarar a resolução directamente em casos excepcionais.
     */
    private function goalsAllResolved(GuardContext $ctx): bool
    {
        if ($ctx->signals->goalsAllResolved) {
            return true;
        }

        return empty($ctx->context->activeGoals);
    }
}
