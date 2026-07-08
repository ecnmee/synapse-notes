---
type: feat
version: v0.3
date: 2026-07-08
supersedes: articles/cortex-guard-dsl/v0.2
lang: pt-AO
companion: pending
references: ./REFERENCES.md
medium_url: pending
---

# A Mini-Linguagem que Controla as Transições da FSM do CortexOS

*[Read in English](../en/article.md)*

Quando a FSM do CortexOS precisou de transições com condições compostas, tinha uma escolha: hardcodar `if` aninhados ou construir algo que se lesse como texto. Escolhi a segunda opção. Este artigo documenta o porquê e o como.

## O Problema Com Condições de Transição

Uma Finite State Machine (FSM) simples tem transições simples. `if (estado == A) vai para B`. Mas quando o agente precisa de decidir se transita de ACT para HANDOFF com base em mais do que um critério, a coisa fica diferente.

No CortexOS, a transição de ACT para HANDOFF_REQUESTED deve acontecer quando:

- A tool pediu handoff explicitamente, **OU**
- Houve pelo menos 2 falhas consecutivas

E a transição de HANDOFF_REQUESTED para HANDOFF_ACTIVE deve acontecer apenas quando:

- O operador aceitou, **E**
- O temporizador ainda não expirou

Como exprimir isto? A alternativa mais directa é esta:

```php
// Hardcoded numa função de transição
if ($signals->toolResultRequestsHandoff || $metrics->consecutiveFailures >= 2) {
    $this->transitionTo(AgentState::HANDOFF_REQUESTED);
}
```

Funciona. Mas agora imagina 15 transições, algumas com 3 ou 4 condições. O mapa de estados fica enterrado em lógica condicional. Para perceber o que a FSM faz, tens de ler código PHP, não um mapa declarativo. Testar uma condição específica obriga a instanciar toda a cadeia.

O CortexOS resolveu isto com uma linguagem de expressão minimalista.

## A Abordagem: Expressões de Guarda como Texto

No `TransitionMap`, cada transição declara as suas guardas como strings:

```php
$define(
    AgentState::ACT,
    AgentState::HANDOFF_REQUESTED,
    guards: [
        'tool_result.requests_handoff OR consecutive_failures >= 2',
    ],
    critical: [],
    deferred: ['start_timeout_timer', 'notify_operators_multichannel'],
);

$define(
    AgentState::HANDOFF_REQUESTED,
    AgentState::HANDOFF_ACTIVE,
    guards: [
        'operator.accepted',
        'NOT timer.expired',
    ],
    critical: ['persist_operator_assignment', 'cancel_timer'],
    deferred: [],
);
```

Estas strings são a linguagem de guarda. O sistema compila-as para ASTs em boot-time e avalia-as em runtime sem voltar a parsear texto.

## A Gramática (5 Regras)

```
expression  ::= or_expr
or_expr     ::= and_expr ("OR" and_expr)*
and_expr    ::= not_expr ("AND" not_expr)*
not_expr    ::= "NOT" not_expr | "(" expression ")" | atom
atom        ::= identifier ">=" integer | identifier
```

É tudo. Operadores em maiúsculas. Precedência: NOT > AND > OR. Parênteses sobrepõem.

![Pipeline de guardas do CortexOS](./diagrams/01-guard-pipeline.svg)

## O Pipeline: Parse em Boot, Avalia em Runtime

O sistema tem uma separação clara entre o que acontece no arranque e o que acontece em runtime.

**Boot-time:** O `GuardParser` recebe cada expressão, tokeniza, e constrói uma AST imutável (`CompiledGuard`). Se a expressão tiver sintaxe inválida ou referenciar um identificador desconhecido, lança `InvalidGuardExpressionException` imediatamente. A aplicação não arranca com um mapa de transições corrompido.

```php
// Acontece uma vez no construtor do TransitionMap
$compiled = array_map(
    fn(string $expression): CompiledGuard => $this->guardParser->compile($expression),
    $guards,
);
```

**Runtime:** O `GuardEvaluator` percorre a AST recursivamente. Quando encontra um nó folha, delega ao `GuardRegistry`, que lê os valores reais dos sinais e métricas.

```php
// Avaliação de um nó na AST
return match ($node->type) {
    GuardNodeType::Signal    => $this->registry->evaluate($node->identifier, $ctx),
    GuardNodeType::Threshold => $this->registry->evaluate(
        "{$node->identifier} >= {$node->threshold}", $ctx,
    ),
    GuardNodeType::Not  => ! $this->evaluateNode($node->left, $ctx),
    GuardNodeType::And  => $this->evaluateNode($node->left, $ctx)
                        && $this->evaluateNode($node->right, $ctx),
    GuardNodeType::Or   => $this->evaluateNode($node->left, $ctx)
                        || $this->evaluateNode($node->right, $ctx),
};
```

O AND e o OR avaliam em short-circuit, tal como no PHP nativo.

![Gramática e árvore de expressão](./diagrams/02-grammar-and-ast.svg)

## Os Átomos: Sinais e Thresholds

A linguagem tem dois tipos de átomo:

**Sinais** são flags booleanas transitórias produzidas durante cada iteração da FSM. Exemplos:

```
policy.permitted              -- a policy engine aprovou a execução
tool_result.received          -- a tool retornou resultado
operator.accepted             -- o operador aceitou o handoff
goals.all_resolved            -- todos os goals activos foram resolvidos
max_iterations_reached        -- limite de 10 iterações atingido
```

**Thresholds** são comparações numéricas contra métricas acumuladas:

```
consecutive_failures >= 2     -- duas ou mais falhas seguidas
```

A separação não é apenas conceptual: o `GuardParser` trata os dois tipos de forma diferente, e o `GuardRegistry` tem listas distintas para cada um. Isto impede que um sinal booleano seja usado em posição de threshold (`operator.accepted >= 2` seria inválido e explode em boot-time).

## Onde Cada Sinal Vem

O `GuardContext` agrega quatro fontes de informação:

- `RuntimeSignals`: flags transitórias da iteração corrente (produzidas pelo CortexAgent durante `runActState()`, `runPolicyCheckState()`, etc.)
- `ExecutionMetrics`: contadores acumulados (iterações, falhas consecutivas, retries)
- `AgentContext`: estado cognitivo persistente (goals activos, políticas, persona)
- `AgentExecution`: campos do modelo de base de dados

O `GuardRegistry` sabe de onde ler cada sinal. A lógica de resolução fica centralizada num só lugar:

```php
return match ($guard) {
    'policy.permitted'             => $ctx->signals->policyPermitted,
    'goals.all_resolved'           => $this->goalsAllResolved($ctx),
    'max_iterations_reached'       => $ctx->metrics->iterationCount >= 10,
    'operator.accepted'            => $ctx->signals->operatorAccepted,
    // ...
};
```

## O Mapa Completo das Transições

![FSM do CortexOS com guardas nas transições](./diagrams/03-fsm-transitions.svg)

O mapa declara todas as transições válidas, com as guardas que as protegem, os efeitos críticos (síncronos, dentro da transacção de base de dados) e os efeitos diferidos (jobs assíncronos). Se um efeito crítico falhar, a transição falha e o estado não avança.

```php
// Transição com efeito crítico e diferido
$define(
    AgentState::UPDATE_MEMORY,
    AgentState::PLAN,
    guards:   [
        'NOT goals.all_resolved',
        'NOT max_iterations_reached',
    ],
    critical: ['persist_memory_updates'],  // falha bloqueia transição
    deferred: [],
);
```

## O que Esta Abordagem Ganha

**Legibilidade.** O `TransitionMap` lê-se como especificação, não como código de controlo. Para perceber o que a FSM faz, basta ler as strings de guarda.

**Fail fast.** Expressão inválida, seja por typo num identificador ou por sintaxe incorrecta, lança excepção no arranque, não numa transição em produção.

**Testabilidade.** O `GuardEvaluator`, o `GuardParser` e o `GuardRegistry` são testáveis de forma completamente independente. Para testar uma guarda específica, basta construir um `GuardContext` com os valores desejados, sem instanciar o `CortexAgent`, sem base de dados, sem LLM.

**Extensão sem risco.** Adicionar um novo sinal requer três passos: adicionar a constante ao `GuardSignals`, adicionar ao `KNOWN_SIGNALS` do `GuardRegistry`, e implementar a resolução no `evaluate()`. O parser e o evaluator ficam intocados.

## O Que Esta Abordagem Não Faz

Não é uma linguagem de propósito geral. Não tem variáveis, funções, loops ou tipos. É especificamente desenhada para exprimir condições booleanas compostas sobre um conjunto fixo de sinais e métricas.

Também não avalia expressões arbitrárias em runtime. O conjunto de sinais é fechado: adicionar um novo sinal requer mudança de código, não apenas uma string diferente na expressão de guarda. Isto é uma decisão deliberada de segurança.

## Referências

Ver [`REFERENCES.md`](./REFERENCES.md).

Para o código completo, incluindo `TransitionMap` com todas as transições declaradas:
https://github.com/ecnmee/synapse-notes/tree/main/articles/cortex-guard-dsl/pt/code

---

Usas FSMs em produção? Como resolves as condições de transição compostas?

Deixa nos comentários.
