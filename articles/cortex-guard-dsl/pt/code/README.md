# code/ - Guard system source backing this article

Real CortexOS source files (comments in Portuguese) that support every
claim made in the article.

## Guard/

| File | Role |
|---|---|
| `GuardParser.php` | Tokenizes and compiles guard expressions into immutable `CompiledGuard` ASTs. Throws `InvalidGuardExpressionException` at boot-time for invalid expressions. |
| `CompiledGuard.php` | Immutable AST node. One of: `Signal`, `Threshold`, `And`, `Or`, `Not`. |
| `GuardNodeType.php` | Enum of AST node types. |
| `GuardEvaluator.php` | Recursively traverses the AST with short-circuit evaluation. Delegates leaf nodes to `GuardRegistry`. |
| `GuardRegistry.php` | The single place that maps a signal name to a real value. Reads from `RuntimeSignals`, `ExecutionMetrics`, `AgentContext`, and `AgentExecution`. |
| `GuardContext.php` | Value object bundling all data sources for the evaluator. |
| `GuardSignals.php` | Constants for all valid signal names (closed set). |
| `GuardThresholds.php` | Constants for identifiers valid in threshold position. |
| `RuntimeSignals.php` | Transient VO with boolean flags produced during each FSM iteration. |

## Kernel/

| File | Role |
|---|---|
| `TransitionMap.php` | Declares all valid FSM transitions with their real guard expressions, critical effects (synchronous, inside the DB transaction), and deferred effects (async jobs). The executable specification of the FSM. |
