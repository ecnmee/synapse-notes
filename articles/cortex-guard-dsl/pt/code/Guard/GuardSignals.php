<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Guard;

/**
 * Constantes tipadas para os identificadores de sinal booleano da FSM V2.
 *
 * Um sinal é um átomo booleano — pode ser usado de forma autónoma
 * numa expressão de guarda (ex: "policy.permitted AND NOT policy.denied").
 *
 * Identificadores de threshold (comparadores numéricos, ex: "consecutive_failures >= 2")
 * vivem em {@see GuardThresholds}. A separação reflecte a distinção estrutural
 * já existente no {@see GuardParser} (existsSignal vs existsThreshold) e no
 * {@see CompiledGuard} (GuardNodeType::Signal vs GuardNodeType::Threshold).
 *
 * O {@see GuardParser} e o {@see GuardRegistry} trabalham com strings —
 * esta classe existe para eliminar literais espalhados pelo {@see \App\Services\V2\Agent\Kernel\TransitionMap}
 * e garantir que um typo é detectado pelo IDE ou pelo compilador estático,
 * não em runtime.
 *
 * Convenção de nomes: o identificador de guarda usa ponto como separador
 * de namespace (ex: "policy.permitted"). A constante PHP usa underscore
 * e maiúsculas (ex: POLICY_PERMITTED).
 *
 * @package App\Services\V2\Agent\Guard
 * @author  Eduardo Costa Nkuansambu
 */
final class GuardSignals
{
    // ─── Policy ──────────────────────────────────────────────────────────────

    public const POLICY_PERMITTED = 'policy.permitted';
    public const POLICY_DENIED    = 'policy.denied';

    // ─── Plan ────────────────────────────────────────────────────────────────

    public const PLAN_HAS_SELECTED_TOOL = 'plan.has_selected_tool';

    // ─── Tool result ─────────────────────────────────────────────────────────

    public const TOOL_RESULT_RECEIVED         = 'tool_result.received';
    public const TOOL_RESULT_REQUESTS_HANDOFF = 'tool_result.requests_handoff';

    // ─── Goals ───────────────────────────────────────────────────────────────

    public const GOALS_ALL_RESOLVED = 'goals.all_resolved';

    // ─── Operator ────────────────────────────────────────────────────────────

    public const OPERATOR_ACCEPTED             = 'operator.accepted';
    public const OPERATOR_REPLIED              = 'operator.replied';
    public const OPERATOR_ASSUMED_FULL_CONTROL = 'operator.assumed_full_control';

    // ─── Timer ───────────────────────────────────────────────────────────────

    public const TIMER_EXPIRED = 'timer.expired';

    // ─── Runtime ─────────────────────────────────────────────────────────────

    public const ALL_CRITICAL_EFFECTS_COMMITTED = 'all_critical_effects_committed';
    public const MAX_ITERATIONS_REACHED         = 'max_iterations_reached';

    private function __construct() {}
}