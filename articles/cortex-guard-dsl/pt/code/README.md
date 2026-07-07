# code/ - implementacao do sistema de guardas

Ficheiros reais do CortexOS que suportam as afirmacoes do artigo.

## Guard/

| Ficheiro | Papel |
|---|---|
| `GuardParser.php` | Tokeniza e compila expressoes de guarda para ASTs (`CompiledGuard`). Lanca `InvalidGuardExpressionException` em boot-time para expressoes invalidas. |
| `CompiledGuard.php` | No de AST imutavel. Pode ser `Signal`, `Threshold`, `And`, `Or`, `Not`. |
| `GuardNodeType.php` | Enum dos tipos de no da AST. |
| `GuardEvaluator.php` | Percorre a AST recursivamente em short-circuit. Delega atomos ao `GuardRegistry`. |
| `GuardRegistry.php` | Sabe de onde ler cada sinal e threshold. Unico lugar onde o nome do sinal e mapeado para um valor real. |
| `GuardContext.php` | Agrega `RuntimeSignals`, `ExecutionMetrics`, `AgentContext` e `AgentExecution` para o evaluator. |
| `GuardSignals.php` | Constantes dos nomes de sinais validos (conjunto fechado). |
| `GuardThresholds.php` | Constantes dos identificadores validos em posicao de threshold. |
| `RuntimeSignals.php` | VO transitorio com flags booleanas produzidas durante cada iteracao da FSM. |

## Kernel/

| Ficheiro | Papel |
|---|---|
| `TransitionMap.php` | Declara todas as transicoes validas da FSM com as guardas reais, efeitos criticos e efeitos diferidos. E a "especificacao executavel" da FSM. |
