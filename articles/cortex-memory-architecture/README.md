# cortex-memory-architecture

Uma arquitectura de memória em 4 camadas (**Working / Episodic / Semantic /
Procedural**) para um kernel de agente de IA em PHP/Laravel (CortexOS),
construída por trás de uma única fachada `MemoryBus`, com um pipeline real
de candidato, validação, promoção, e não apenas busca vectorial envolta
num prompt.

## Conteúdo

- [`pt/article.md`](./pt/article.md): o artigo publicado, em português (Angola). Publicado no Medium: https://medium.com/@ecnmee/as-4-camadas-de-mem%C3%B3ria-que-podem-mudar-a-forma-como-constru%C3%ADmos-agentes-de-ia-24ad18c26dd0
- [`pt/diagrams/`](./pt/diagrams): fontes SVG dos três diagramas referenciados no artigo (legendas em português):
  - `01-four-layers.svg`: as quatro camadas de memória e o seu modelo de persistência.
  - `02-memory-bus-facade.svg`: como o `MemoryBus` isola os consumidores do armazenamento concreto.
  - `03-learning-cycle.svg`: o ciclo de candidato, validação, promoção.
- [`pt/code/`](./pt/code): implementação real (`Memory/`) e migrations que sustentam cada afirmação técnica do artigo, comentários em português. Ver [`pt/code/README.md`](./pt/code/README.md) para o mapeamento ficheiro-a-afirmação.
- [`pt/REFERENCES.md`](./pt/REFERENCES.md): bibliografia completa, com notas sobre onde esta arquitectura segue trabalho anterior e onde diverge.
- [`en/`](./en): versão em inglês, ainda pendente. Quando o código for traduzido, `en/code/` e `en/diagrams/` seguem a mesma estrutura de `pt/`. Ver [`en/README.md`](./en/README.md).
- [`CHANGELOG.md`](./CHANGELOG.md): o que mudou entre versões, e porquê.

## Porquê código e diagramas dentro de pt/

Os comentários do código real e as legendas dos diagramas estão em
português, por isso vivem dentro de `pt/`, junto do artigo que os usa como
prova. Quando a versão inglesa existir (depois dos comentários do CortexOS
serem traduzidos), `en/` terá a sua própria cópia de `code/` e `diagrams/`
com texto em inglês, em vez de partilhar ficheiros entre as duas versões.

## Versionamento

Cada ficheiro de artigo começa com um cabeçalho de metadata (`type`,
`version`, `date`, `supersedes`). Ver [`/VERSIONING.md`](../../VERSIONING.md)
na raiz do repositório para a convenção completa: cada edição substancial
sobe a versão, recebe uma tag git, e a nova versão liga explicitamente para
a que substitui. Versões antigas continuam acessíveis pela sua tag.

## A ideia central, num parágrafo

A maioria das frameworks de agentes optimiza para *recuperação*. Esta
arquitectura optimiza para *acumulação e validação*: factos e
procedimentos entram como candidatos não validados, só são promovidos
depois de cumprirem limiares explícitos (número de confirmações,
confiança média, tamanho de amostra, taxa de sucesso) e, criticamente,
candidatos nunca são apagados nem editados. Correcções criam novos
registos; os antigos ficam para auditoria. Essa única regra de
governação (nunca mutar, sempre superseder) é o que torna a memória
suficientemente fiável para agir sem um humano no circuito, em decisões
de baixo impacto.
