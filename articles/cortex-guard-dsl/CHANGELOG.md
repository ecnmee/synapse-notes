# Changelog, cortex-guard-dsl

Tracks substantive edits to the article, not the original draft as written.
Tag naming follows [`/VERSIONING.md`](../../VERSIONING.md).

## v0.2, fix: README and CHANGELOG rewritten in English, glossary added

- supersedes: `articles/cortex-guard-dsl/v0.1`
- Rewrote `README.md` and `CHANGELOG.md` in English; these are
  repository navigation files, not article content, and follow the same
  convention as `cortex-memory-architecture` where support files are in
  English and article text is in the publication language.
- Added a "Assumed knowledge" glossary to `README.md` covering
  CortexOS-specific terms (AgentState, HANDOFF_REQUESTED, HANDOFF_ACTIVE,
  RuntimeSignals, ExecutionMetrics) so readers arriving from the
  memory-architecture article have a reference without re-reading it.
- Fixed missing accents and encoding issues in support files introduced
  during initial drafting.

## v0.1, feat: initial article, diagrams, code and references

- First version: article in Portuguese (Angola), 3 SVG diagrams, full
  Guard system source code and `TransitionMap` with all declared FSM
  transitions.
- English version pending until code comments are translated.
