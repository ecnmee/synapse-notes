# cortex-guard-dsl

Uma mini-linguagem de expressao booleana para controlar transicoes de FSM
no CortexOS, com parse em boot-time e avaliacao em runtime sobre ASTs
imutaveis.

## Conteudo

- [`pt/article.md`](./pt/article.md): o artigo em portugues (Angola).
- [`pt/diagrams/`](./pt/diagrams): 3 SVGs - pipeline de guardas, gramatica e AST, mapa de transicoes da FSM.
- [`pt/code/Guard/`](./pt/code/Guard): implementacao completa do sistema de guardas.
- [`pt/code/Kernel/TransitionMap.php`](./pt/code/Kernel/TransitionMap.php): todas as transicoes declaradas com guardas reais.
- [`pt/REFERENCES.md`](./pt/REFERENCES.md): bibliografia sobre compiladores, DSLs e FSMs.
- [`en/`](./en): versao inglesa pendente.
- [`CHANGELOG.md`](./CHANGELOG.md): historico de versoes.

## Ideia central

Quando condicoes de transicao de FSM ficam compostas, hardcodar `if`
aninhados enterra a logica de estados no codigo de controlo. A linguagem
de guarda do CortexOS permite declarar as condicoes como strings
legíveis, compila-as para ASTs no arranque (falha rapido se invalidas) e
avalia-as em runtime sem reparse. `GuardParser`, `GuardEvaluator` e
`GuardRegistry` sao testáveis de forma completamente independente.
