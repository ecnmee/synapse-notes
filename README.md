# synapse-notes

Notes, write-ups and reference diagrams on **software architecture, systems
design and AI agent engineering**, extracted from real production code,
not from theory alone.

## Index

| Article | Topic | Languages | Status |
|---|---|---|---|
| [`cortex-memory-architecture`](./articles/cortex-memory-architecture) | A 4-layer memory architecture (Working / Episodic / Semantic / Procedural) for AI agents in PHP | PT, EN | v1.1 |
| [`cortex-guard-dsl`](./articles/cortex-guard-dsl) | A minimal boolean expression language for FSM transitions: parse at boot-time, runtime evaluation over ASTs | PT | v0.3 (EN pending) |

## Conventions

- Each article has a `pt/` and an `en/` folder. The English version is only
  published once the code comments are translated.
- Code, diagrams and folder/file names are always in English.
- Each article carries a frontmatter header: `type`, `version`, `date`,
  `supersedes`, `medium_url`. See [`VERSIONING.md`](./VERSIONING.md).
- `REFERENCES.md` inside each article documents what comes from prior work
  and what is an operational decision specific to this system.
- `README.md` and `CHANGELOG.md` are always in English.
- Commits follow conventional commits in English.

## Author

Software developer working on backend systems, ERP architecture, and AI
agent kernels. Open to discussing any of this in detail.
