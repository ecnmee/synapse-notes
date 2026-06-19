<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Episodic;

/**
 * Resultado final observável de uma execução do agente.
 *
 * Camada histórica — independente da FSM operacional.
 * Se a topologia da FSM mudar, este enum não muda.
 *
 * Responde apenas a: "como terminou esta execução?"
 *
 * ─── Casos ───────────────────────────────────────────────────────────────────
 *
 * SUCCESS         Todos os goals activos foram resolvidos pelos outcomes observados.
 *                 Definido pela resolução de goals, não pelo número de iterações.
 *                 Uma execução com 1 iteração pode ser SUCCESS.
 *                 Uma execução com 20 iterações também pode ser SUCCESS.
 *
 * PARTIAL_SUCCESS Pelo menos um goal foi resolvido, mas outros ficaram pendentes.
 *                 Causa típica: limite de iterações (MAX_ITERATIONS_REACHED)
 *                 antes da resolução completa.
 *
 * HANDOFF         A execução foi transferida para um operador humano e foi aceite.
 *                 Indica que a escalada foi bem-sucedida — um operador respondeu
 *                 e tomou responsabilidade pelo caso.
 *                 Não é suficiente pedir escalada; o operador deve ter aceitado.
 *
 * TIMEOUT         A execução foi escalada (HANDOFF solicitado) mas nenhum operador
 *                 respondeu dentro do tempo limite. Indica falha operacional
 *                 da subsistema de handoff, não da cognição do agente.
 *
 * FAILURE         Nenhum goal foi resolvido. Inclui:
 *                 - falhas consecutivas de tools
 *                 - limite de iterações sem qualquer resolução
 *                 - policy blocks que impedem execução (POLICY_CHECK → COMPLETE)
 *                 - outras condições que interrompem o ciclo sem sucesso
 *
 * ─── Uso pelo Learner ────────────────────────────────────────────────────────
 *
 * O Learner usa este campo para perguntas como:
 *   "Quais goals alinham mas nunca resolvem?"        → cruzar alignedGoals com FAILURE/PARTIAL_SUCCESS
 *   "Qual é a taxa real de escalada bem-sucedida?"   → proporção HANDOFF vs (TIMEOUT + FAILURE)
 *   "Quais revisões melhoraram sucesso?"             → agrupar por revision + outcome
 *   "Handoffs correlacionam com que goals?"          → filtrar HANDOFF, agregar alignedGoals
 *   "Timeouts aumentaram após mudança X?"            → série temporal de TIMEOUT
 *
 * ─── Ortogonalidade de TIMEOUT vs HANDOFF ────────────────────────────────────
 *
 * Ambos representam cenários onde a execução foi escalada, mas com resultados
 * operacionais distintos:
 *
 *   HANDOFF  = escalada bem-sucedida (operador aceitou)
 *   TIMEOUT  = escalada falhou (operador não respondeu)
 *
 * Manter ambos separados permite ao Learner distinguir entre falhas de
 * disponibilidade operacional (TIMEOUT) e sucesso de escalada (HANDOFF).
 *
 * ─── Derivação do outcome ─────────────────────────────────────────────────────
 *
 * O CortexAgent não deriva EpisodeOutcome sozinho.
 * O outcome é derivado pelo handler de persistência (persist_agent_result),
 * que conhece o estado final da FSM e do subsistema de handoff:
 *
 *   COMPLETE + allGoalsResolved             → SUCCESS
 *   COMPLETE + someGoalsResolved            → PARTIAL_SUCCESS
 *   COMPLETE + policy denied                → FAILURE
 *   HANDOFF_REQUESTED + operador aceitou    → HANDOFF
 *   HANDOFF_REQUESTED + timeout expirou     → TIMEOUT
 *
 * @package App\Services\V2\Agent\Memory\Episodic
 * @author  Eduardo Costa Nkuansambu
 */
enum EpisodeOutcome: string
{
    case SUCCESS         = 'success';
    case PARTIAL_SUCCESS = 'partial_success';
    case HANDOFF         = 'handoff';
    case TIMEOUT         = 'timeout';
    case FAILURE         = 'failure';
}