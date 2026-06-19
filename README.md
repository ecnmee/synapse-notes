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

| Article | Topic | Languages | Status |
|---|---|---|---|
| [`cortex-memory-architecture`](./articles/cortex-memory-architecture) | A 4-layer memory architecture (Working / Episodic / Semantic / Procedural) for PHP-based AI agents | PT-AO, EN | v0.3 |

## Conventions

- **Language**: every article is published in at least Portuguese (Angola)
  and English, as two separate files that link to each other; code,
  diagrams and folder/file names are always in English.
- **`diagrams/`**: SVG sources, no binary exports, so diffs stay readable.
- **Versioning**: every article carries a metadata header (`type`,
  `version`, `date`, `supersedes`). See [`VERSIONING.md`](./VERSIONING.md)
  for the full convention: an updated idea never silently overwrites a
  published version, it links back to it explicitly.
- **`REFERENCES.md`** inside each article folder: the bibliography behind
  every non-trivial claim, with a note on what's drawn from prior work and
  what's this system's own operational decision.
- **`CHANGELOG.md`** inside each article folder tracks substantive edits,
  not typos, but changes to claims, structure, or technical accuracy.
- Code excerpts are anonymized/trimmed from a real system; they illustrate
  a pattern, not a tutorial to copy-paste.

## Author

Software developer working on backend systems, ERP architecture, and AI
agent kernels. Open to discussing any of this in detail.
