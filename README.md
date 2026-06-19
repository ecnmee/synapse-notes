# synapse-notes

Notes, write-ups and reference diagrams on **software architecture, systems
design and AI agent engineering**, extracted from real production code,
not from theory alone.

Each folder in `articles/` is one published piece. Where it makes sense,
the original code excerpts and diagrams that back the claims in the article
live alongside it, so the writing stays honest and verifiable.

## Why this repo exists

Most public writing about AI agents stops at the prompt-engineering layer.
This repo documents the layer underneath: state machines, persistence
boundaries, validation pipelines, governance invariants, the parts that
decide whether an agent actually gets *better* over time, or just gets
*bigger context windows*.

## Index

| Article | Topic | Idiomas | Estado |
|---|---|---|---|
| [`cortex-memory-architecture`](./articles/cortex-memory-architecture) | Uma arquitectura de memória em 4 camadas (Working / Episodic / Semantic / Procedural) para agentes de IA em PHP | PT | v0.4 (EN pendente) |

## Conventions

- **Idioma**: cada artigo tem uma pasta `pt/` e uma pasta `en/`. A versão
  inglesa só é publicada quando o código fonte que o artigo documenta já
  tiver os comentários traduzidos, para o artigo nunca ficar adiantado em
  relação ao código. Código, diagramas e nomes de pastas/ficheiros estão
  sempre em inglês.
- **`diagrams/`**: fontes SVG, sem exportações binárias, para os diffs
  ficarem legíveis.
- **Versionamento**: cada artigo tem um cabeçalho de metadata (`type`,
  `version`, `date`, `supersedes`). Ver [`VERSIONING.md`](./VERSIONING.md)
  para a convenção completa: uma ideia actualizada nunca substitui uma
  versão publicada em silêncio, ela liga explicitamente para a anterior.
- **`REFERENCES.md`** dentro de cada pasta de artigo: a bibliografia por
  trás de cada afirmação não trivial, com nota sobre o que vem de trabalho
  anterior e o que é decisão operacional própria deste sistema.
- **`CHANGELOG.md`** dentro de cada pasta de artigo: regista edições
  substanciais, não erros de digitação, mas alterações a afirmações,
  estrutura, ou precisão técnica.
- Excertos de código são anonimizados/reduzidos de um sistema real;
  ilustram um padrão, não são um tutorial para copiar e colar.

## Author

Software developer working on backend systems, ERP architecture, and AI
agent kernels. Open to discussing any of this in detail.
