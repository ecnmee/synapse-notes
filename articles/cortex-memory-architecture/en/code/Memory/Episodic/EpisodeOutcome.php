<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory\Episodic;

/**
 * Observable final outcome of an agent execution.
 *
 * Historical layer, independent of the operational FSM.
 * If the FSM's topology changes, this enum doesn't change.
 *
 * Answers only: "how did this execution end?"
 *
 * --- Cases ---
 *
 * SUCCESS         All active goals were resolved by the observed outcomes.
 *                 Defined by goal resolution, not by the number of iterations.
 *                 An execution with 1 iteration can be SUCCESS.
 *                 An execution with 20 iterations can also be SUCCESS.
 *
 * PARTIAL_SUCCESS At least one goal was resolved, but others remained pending.
 *                 Typical cause: iteration limit (MAX_ITERATIONS_REACHED)
 *                 reached before full resolution.
 *
 * HANDOFF         The execution was transferred to a human operator and was accepted.
 *                 Indicates the escalation succeeded, an operator responded
 *                 and took responsibility for the case.
 *                 It's not enough to request escalation; the operator must have accepted.
 *
 * TIMEOUT         The execution was escalated (HANDOFF requested) but no
 *                 operator responded within the time limit. Indicates an
 *                 operational failure of the handoff subsystem, not of the
 *                 agent's cognition.
 *
 * FAILURE         No goal was resolved. Includes:
 *                 - consecutive tool failures
 *                 - iteration limit reached with no resolution at all
 *                 - policy blocks that prevent execution (POLICY_CHECK -> COMPLETE)
 *                 - other conditions that interrupt the cycle without success
 *
 * --- Use by the Learner ---
 *
 * The Learner uses this field for questions like:
 *   "Which goals align but never resolve?"          -> cross alignedGoals with FAILURE/PARTIAL_SUCCESS
 *   "What's the real successful escalation rate?"    -> ratio of HANDOFF vs (TIMEOUT + FAILURE)
 *   "Which revisions improved success?"              -> group by revision + outcome
 *   "What goals correlate with handoffs?"             -> filter HANDOFF, aggregate alignedGoals
 *   "Did timeouts increase after change X?"           -> TIMEOUT time series
 *
 * --- Orthogonality of TIMEOUT vs HANDOFF ---
 *
 * Both represent scenarios where the execution was escalated, but with
 * distinct operational outcomes:
 *
 *   HANDOFF  = successful escalation (operator accepted)
 *   TIMEOUT  = failed escalation (operator didn't respond)
 *
 * Keeping both separate lets the Learner distinguish between operational
 * availability failures (TIMEOUT) and escalation success (HANDOFF).
 *
 * --- Deriving the outcome ---
 *
 * CortexAgent doesn't derive EpisodeOutcome on its own.
 * The outcome is derived by the persistence handler (persist_agent_result),
 * which knows the FSM's final state and the handoff subsystem's state:
 *
 *   COMPLETE + allGoalsResolved             -> SUCCESS
 *   COMPLETE + someGoalsResolved            -> PARTIAL_SUCCESS
 *   COMPLETE + policy denied                -> FAILURE
 *   HANDOFF_REQUESTED + operator accepted   -> HANDOFF
 *   HANDOFF_REQUESTED + timeout expired     -> TIMEOUT
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
