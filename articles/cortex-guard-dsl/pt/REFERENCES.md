# Referências - cortex-guard-dsl

## Compiladores e linguagens de domínio específico (DSL)

- Aho, A. V., Lam, M. S., Sethi, R., Ullman, J. D. (2006). *Compilers: Principles, Techniques, and Tools* (2nd ed.). Pearson.
  - Referência clássica para parsing recursivo descendente, o mesmo algoritmo usado no `GuardParser`. O capítulo sobre análise sintáctica cobre a técnica de forma completa.

- Fowler, M. (2010). *Domain-Specific Languages*. Addison-Wesley.
  - Categoriza DSLs internas vs externas. A linguagem de guarda do CortexOS é uma DSL interna: as expressões são strings PHP, não ficheiros numa linguagem separada.

- Parr, T. (2013). *The Definitive ANTLR 4 Reference*. Pragmatic Bookshelf.
  - Referência prática para gramáticas LL. A gramática de guarda do CortexOS é LL(1): cada produção é determinística com um token de lookahead.

## Finite State Machines em software

- Gamma, E., Helm, R., Johnson, R., Vlissides, J. (1994). *Design Patterns: Elements of Reusable Object-Oriented Software*. Addison-Wesley.
  - O padrão State (pp. 305-313) descreve a motivação para FSMs explícitas. O `TransitionMap` do CortexOS é uma implementação declarativa deste padrão.

- Mili, A., Tchier, F. (2015). *Software Testing: Concepts and Operations*. Wiley.
  - Aborda testabilidade de FSMs. A separação entre `GuardParser` (boot-time), `GuardEvaluator` (runtime) e `GuardRegistry` (avaliação atómica) é motivada exactamente pela testabilidade independente de cada componente.

## Onde o CortexOS diverge

- A gramática de guarda não tem variáveis, funções, loops ou tipagem. Não é Turing-completa e não se pretende ser. O conjunto de sinais é fechado: adicionar um sinal requer mudança de código PHP, não apenas uma string diferente. Esta é uma decisão deliberada: expressividade máxima para o caso de uso específico, sem complexidade desnecessária.
- A separação boot-time/runtime (parse uma vez, avalia muitas vezes) segue o princípio de compiladores reais, mas o "compilador" do CortexOS é trivialmente simples comparado com qualquer compilador de produção. A AST não é optimizada nem transformada: é avaliada directamente.
