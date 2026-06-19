# cortex-memory-architecture

A 4-layer memory architecture (**Working / Episodic / Semantic / Procedural**)
for a PHP/Laravel-based AI agent kernel (CortexOS), built behind a single
`MemoryBus` facade with a real candidate → validate → promote learning
pipeline — not just vector search wrapped in a prompt.

## Contents

- [`article.pt-AO.md`](./article.pt-AO.md) — the published article (Portuguese, Angola).
- [`diagrams/`](./diagrams) — SVG sources for the three diagrams referenced in the article:
  - `01-four-layers.svg` — the four memory layers and their persistence model.
  - `02-memory-bus-facade.svg` — how the `MemoryBus` isolates consumers from concrete storage.
  - `03-learning-cycle.svg` — the candidate → validate → promote feedback loop.
- [`CHANGELOG.md`](./CHANGELOG.md) — what changed between the first draft and the published version, and why.

## Core ideas, in one paragraph

Most agent frameworks optimize for *retrieval*. This architecture optimizes
for *accumulation and validation*: facts and procedures enter as unvalidated
candidates, get promoted only after meeting explicit thresholds (confirmation
count, confidence average, sample size, success rate), and — critically —
candidates are never deleted or edited. Corrections create new records;
the old ones stay for audit. That single governance rule (never mutate,
always supersede) is what makes the memory trustworthy enough to act on
without a human in the loop for low-impact decisions.
