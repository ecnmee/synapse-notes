# Referencias - cortex-guard-dsl

## Compiladores e linguagens de dominio especifico (DSL)

- Aho, A. V., Lam, M. S., Sethi, R., Ullman, J. D. (2006). *Compilers: Principles, Techniques, and Tools* (2nd ed.). Pearson.
  - Referencia classica para parsing recursivo descendente, o mesmo algoritmo usado no `GuardParser`. O capitulo sobre analise sintatica cobre a tecnica de forma completa.

- Fowler, M. (2010). *Domain-Specific Languages*. Addison-Wesley.
  - Categoriza DSLs internas vs externas. A linguagem de guarda do CortexOS e uma DSL interna: as expressoes sao strings PHP, nao ficheiros numa linguagem separada.

- Parr, T. (2013). *The Definitive ANTLR 4 Reference*. Pragmatic Bookshelf.
  - Referencia pratica para gramaticas LL. A gramatica de guarda do CortexOS e LL(1) - cada producao e deterministica com um token de lookahead.

## Finite State Machines em software

- Gamma, E., Helm, R., Johnson, R., Vlissides, J. (1994). *Design Patterns: Elements of Reusable Object-Oriented Software*. Addison-Wesley.
  - O padrao State (pp. 305-313) descreve a motivacao para FSMs explicitas. O `TransitionMap` do CortexOS e uma implementacao declarativa deste padrao.

- Mili, A., Tchier, F. (2015). *Software Testing: Concepts and Operations*. Wiley.
  - Aborda testabilidade de FSMs. A separacao entre `GuardParser` (boot-time), `GuardEvaluator` (runtime) e `GuardRegistry` (avaliacao atomica) e motivada exactamente pela testabilidade independente de cada componente.

## Onde o CortexOS diverge

- A gramatica de guarda nao tem variaveis, funcoes, loops ou tipagem. Nao e Turing-completa e nao se pretende ser. O conjunto de sinais e fechado: adicionar um sinal requer mudanca de codigo PHP, nao apenas uma string diferente. Esta e uma decisao deliberada - expressividade maxima para o caso de uso especifico, sem complexidade desnecessaria.
- A separacao boot-time/runtime (parse uma vez, avalia muitas vezes) segue o principio de compiladores reais, mas o "compilador" do CortexOS e trivialmente simples comparado com qualquer compilador de producao. A AST nao e optimizada nem transformada - e avaliada directamente.
