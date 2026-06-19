# Changelog — cortex-memory-architecture

Tracks substantive edits to the article, not the original draft as written.

## v0.2 — revised for publication

**Structure**
- Added three diagrams (`diagrams/01` to `03`), referenced inline at the
  point each layer/concept is introduced, instead of describing the
  architecture in prose only.
- Split the original single-document draft into `article.pt-AO.md` +
  `README.md` + this changelog, so the repo separates "what was published"
  from "what backs it up."

**Technical accuracy (grounded in the actual source files)**
- Replaced the generic "validador verifica se o facto aparece várias vezes
  com boa confiança" with the real thresholds from `SemanticValidator`:
  minimum 3 independent confirmations, average confidence ≥ 0.80.
- Replaced the generic procedural-memory description with the real
  auto-activation rule from `ProceduralMemory`: sample size ≥ 20 and
  success rate ≥ 0.85 → automatic activation, but only for low-impact
  procedures; high-impact ones go to `pending_approval` regardless of
  the numbers.
- Added the "never delete, always supersede" governance rule
  (`SemanticMemory::supersede()`), which the original draft didn't
  mention at all — this is arguably the single most defensible technical
  decision in the whole architecture and was previously left out.
- Added a short section on `MemoryBus` as the single access facade,
  including *why* it exists (testability via `MemoryBusInterface`,
  since `MemoryBus` itself is `final`) and *what it buys operationally*
  (per-layer failure isolation via individual try/catch blocks, so an
  episodic-memory outage doesn't take down the whole agent).

**Tone**
- Tightened repetitive phrasing from the AI-assisted first pass
  ("Fiz com tom mais humano..." framing removed entirely — that was an
  artifact of the editing process, not part of the article).
- Adjusted vocabulary and phrasing toward Portuguese as spoken/written in
  Angola rather than generic PT-PT/PT-BR defaults.

## v0.1 — first draft

Initial version: four-layer description in prose, no diagrams, no
source-grounded thresholds (validation criteria and activation rules were
described qualitatively rather than with the actual numbers from the code).
