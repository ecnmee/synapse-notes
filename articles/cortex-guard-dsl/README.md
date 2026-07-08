# cortex-guard-dsl

A minimal boolean expression language for controlling FSM transitions in
CortexOS, with parse-at-boot-time and runtime evaluation over immutable
ASTs.

## Assumed knowledge

This article builds on the CortexOS memory architecture described in
[`cortex-memory-architecture`](../cortex-memory-architecture). Familiarity
with the following terms is assumed:

- **CortexOS**: the PHP/Laravel AI agent kernel behind Simplifika AI.
- **FSM**: the Finite State Machine that governs agent execution lifecycle.
- **AgentState**: the enum of FSM states (PLAN, POLICY_CHECK, ACT, OBSERVE,
  REFLECT, UPDATE_MEMORY, COMPLETE, HANDOFF_REQUESTED, HANDOFF_ACTIVE, CLOSED).
- **HANDOFF_REQUESTED / HANDOFF_ACTIVE**: states triggered when the agent
  cannot resolve a goal and escalates to a human operator.
- **RuntimeSignals**: transient boolean flags produced during each FSM
  iteration (e.g. `tool_result.requests_handoff`, `policy.permitted`).
- **ExecutionMetrics**: accumulated counters per execution
  (e.g. `consecutive_failures`, `iteration_count`).

## Contents

- [`pt/article.md`](./pt/article.md): the article in Portuguese (Angola).
- [`pt/diagrams/`](./pt/diagrams): 3 SVGs: guard pipeline, grammar and AST, FSM transition map.
- [`pt/code/Guard/`](./pt/code/Guard): full Guard system source (`GuardParser`, `GuardEvaluator`, `CompiledGuard`, `GuardRegistry`, `GuardNodeType`, `GuardContext`, `GuardSignals`, `GuardThresholds`, `RuntimeSignals`).
- [`pt/code/Kernel/TransitionMap.php`](./pt/code/Kernel/TransitionMap.php): all FSM transitions declared with real guard expressions.
- [`pt/REFERENCES.md`](./pt/REFERENCES.md): bibliography on compilers, DSLs, and FSMs.
- [`en/`](./en): English version pending until code comments are translated.
- [`CHANGELOG.md`](./CHANGELOG.md): version history.

## Core idea

When FSM transition conditions become compound boolean expressions,
hardcoding nested `if` statements buries state machine logic inside
control flow code. CortexOS's guard language lets you declare conditions
as readable strings, compiles them into ASTs at boot-time (failing fast
on invalid expressions), and evaluates them at runtime without reparsing.
`GuardParser`, `GuardEvaluator`, and `GuardRegistry` are fully
independently testable.
