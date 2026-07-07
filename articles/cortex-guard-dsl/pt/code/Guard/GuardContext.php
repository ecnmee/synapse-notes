<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Guard;

use App\Models\V2\Agent\AgentExecution;
use App\Services\V2\Agent\Kernel\AgentContext;
use App\Services\V2\Agent\Kernel\ExecutionMetrics;

/**
 * Envelope imutável passado ao {@see GuardRegistry} durante a avaliação de guardas.
 *
 * Agrega os quatro canais de informação disponíveis numa transição:
 *   - {@see AgentContext}     → estado cognitivo persistente (goals, memory, policies, persona)
 *   - {@see AgentExecution}   → campos de primeira classe do modelo (state, tenant_id, session_id)
 *   - {@see ExecutionMetrics} → contadores acumulados da execução (iterations, failures, retries)
 *   - {@see RuntimeSignals}   → flags booleanas transitórias da iteração corrente
 *
 * Regra de leitura no {@see GuardRegistry}:
 *   - Contadores históricos  → $metrics
 *   - Flags de iteração      → $signals
 *   - Estado cognitivo       → $context
 *   - Campos do modelo DB    → $execution
 *
 * O registry nunca acede a AgentContext::$metadata para resolver sinais da FSM.
 *
 * @package App\Services\V2\Agent\Guard
 * @author  Eduardo Costa Nkuansambu
 */
final class GuardContext
{
    public function __construct(
        public readonly AgentContext    $context,
        public readonly AgentExecution  $execution,
        public readonly ExecutionMetrics $metrics,
        public readonly RuntimeSignals  $signals,
    ) {}
}
