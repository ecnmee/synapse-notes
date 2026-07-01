# Changelog, cortex-memory-architecture

Tracks substantive edits to the article, not the original draft as written.
Tag naming follows [`/VERSIONING.md`](../../VERSIONING.md).

## v0.8, feat: add English code translations (en/code/)

- supersedes: `articles/cortex-memory-architecture/v0.7`
- Added `en/code/Memory/` with all 19 PHP files translated to English
  comments and docblocks (code, class names, method names and variables
  unchanged): `MemoryBus`, `MemoryBusInterface`, `WorkingMemory`,
  `WorkingMemoryInterface`, `EpisodicMemory`, `EpisodicMemoryInterface`,
  `SemanticMemory`, `SemanticValidator`, `SemanticConflictResolver`,
  `ConflictResolution`, `ProceduralMemory`, `ProceduralMemoryInterface`,
  `ProceduralHealthMonitor`, `Policy/PolicyObservationRepository`, and
  all 5 files under `Episodic/` (`Episode`, `EpisodeOutcome`,
  `EpisodeRepository`, `EpisodeRepositoryInterface`,
  `EpisodeToolTraceRepository`).
- Added `en/code/migrations/` with all 6 migrations translated to
  English docblocks, identical filenames and schema to `pt/code/migrations/`.
- Added `en/code/README.md` with the file-to-claim mapping in English,
  mirroring `pt/code/README.md`.
- Updated `en/README.md` from a blank placeholder to reflect that the
  code is ready; the article text (en/article.md) is still pending
  review before publication.

## v0.7, feat: sync code with P5.1 (conflict resolution, procedural health monitor)

- supersedes: `articles/cortex-memory-architecture/v0.6`
- Updated `pt/code/Memory/` to the current CortexOS implementation
  (P5.1). The published article text (Medium) was **not** rewritten
  retroactively; instead, a short "Nota de evolução" was added near the
  end of `pt/article.md`, pointing to what changed in the code since
  publication.
- Added `SemanticConflictResolver.php` and `ConflictResolution.php`:
  before promoting a semantic candidate, the validator now checks for an
  existing active fact on the same entity with a different claim. Higher
  confidence wins and supersedes the old fact (via `SemanticMemory::supersede()`);
  lower confidence is rejected. Previously, `SemanticValidator` only
  checked "already active", with no handling for conflicting claims.
- Added `ProceduralHealthMonitor.php`: deactivates `active` procedures
  whose `success_rate` falls below 0.60, gated by a minimum sample size
  (30) and a 7-day grace period after activation. This closes a gap the
  article explicitly flagged as unimplemented in `REFERENCES.md`
  (compared to MemoryBank's forgetting-curve approach); updated that
  note to describe the actual threshold-based mechanism instead.
- Simplified the procedural pipeline: removed the `validated` status.
  No component ever implemented the `scored -> validated` transition; it
  was, in the new migration's own words, "a ghost state". Pipeline is
  now `candidate -> scored -> active | pending_approval`.
- Added `ProceduralMemory::bootstrapCandidate()`: seeds a procedure
  directly into `scored` status with historical metrics from the
  PatternDetector, instead of starting every procedure at zero.
- Added `Episodic/EpisodeToolTraceRepository.php` and clarified that
  `EpisodeRepository` now persists to a new `agent_domain_episodes`
  table (immutable domain episodes), explicitly separate from the
  legacy `agent_episodes` table used by `EpisodicMemory` for the
  Python-compression pipeline. `EpisodeRepositoryInterface::find()` now
  requires an explicit `tenant_id`.
- `migrations/`: replaced the 6 previously included files with their
  current versions (renumbered, with the `tool_trace`/`alignment_traces`
  columns folded directly into the `agent_episodes` creation migration
  instead of a separate `add_tool_trace` migration, which no longer
  exists); added `2026_06_20_000001_create_agent_domain_episodes_table.php`;
  added the `idx_semantic_tenant_entity_status` index required by the
  conflict resolver.
- Updated `pt/code/README.md` with the new file-to-claim mapping.

## v0.6, refactor: move code/ and diagrams/ inside pt/, add real Memory/ source

- supersedes: `articles/cortex-memory-architecture/v0.5`
- Moved `diagrams/` and `code/` from the article root into `pt/`, since
  their comments and captions are in Portuguese; they belong with the
  Portuguese article, not shared at the article root. When `en/` is
  published, it gets its own `code/` and `diagrams/` with English text,
  not a shared copy.
- Added `pt/code/Memory/`: the real implementation files for the four
  layers (`MemoryBus`, `WorkingMemory`, `EpisodicMemory`, `SemanticMemory`
  + `SemanticValidator`, `ProceduralMemory`, plus `Episodic/` and
  `Policy/` subfolders), mirroring the actual CortexOS namespace
  structure. Previously only the migrations were included; the article
  now ships the actual classes its claims describe (the 3-confirmation
  / 0.80-confidence rule in `SemanticValidator`, the 20-sample /
  0.85-success-rate rule in `ProceduralMemory`, etc.).
- Fixed every internal link in `pt/article.md` and `pt/code/README.md`
  to the new paths.

## v0.5, feat: link to published Medium article, source migrations

- supersedes: `articles/cortex-memory-architecture/v0.4`
- Added `medium_url` to the frontmatter, pointing to the article actually
  published on Medium, so the repo and the live publication stay linked
  explicitly instead of drifting apart silently.
- Synced small wording edits made directly in the published draft: "há 3
  meses atrás" (was vague "há um tempo"), explicit mention that prompt
  stuffing was the technique used in the kernel's v1, and that the memory
  architecture is the kernel's v2.
- Added a closing line pointing readers to the code backing the article
  (`code/`), matching the placeholder left in the published draft
  ("Para avaliar o código, aceda:").
- Added `code/migrations/`: the 6 real migrations that map directly to
  claims in the article (episodes, tool_trace, episode_tool_traces,
  semantic_memory, procedures, policy_observations), plus
  `code/README.md` mapping each file to the specific claim it backs, and
  listing which of the uploaded files were intentionally left out (FSM
  core, reflection tier 2, strategic alignment, KB pipeline, and their
  exceptions) because they belong to other subsystems, not this article.

## v0.4, refactor: pt/en folder split, fixed-up links

- supersedes: `articles/cortex-memory-architecture/v0.3`
- Moved the Portuguese article from `article.pt-AO.md` to `pt/article.md`,
  and the bibliography from `REFERENCES.md` to `pt/REFERENCES.md`
  (translated to Portuguese).
- Removed `article.en.md`. The English version is not published yet: the
  underlying CortexOS code comments are still entirely in Portuguese, and
  the article should not get ahead of the source it documents. An `en/`
  folder exists as a placeholder with a `README.md` explaining why.
- Fixed every internal link (diagrams, references, companion-language
  link) to use paths relative to the actual repo structure, not to any
  external tool or session the content was drafted in.
- Repo's README updated to reflect the `pt/` / `en/` split and to keep
  this article's own README in Portuguese until the English version
  exists.

## v0.3, feat: bilingual version, bibliography, versioning metadata

- supersedes: `articles/cortex-memory-architecture/v0.2`
- Added `article.en.md`, an English version of the article kept in sync
  with `article.pt-AO.md`. Each file links to the other.
- Added [`REFERENCES.md`](./pt/REFERENCES.md): full bibliography for every
  claim in the article (RAG paper, CoALA, ACT-R, SOAR, Generative Agents,
  MemGPT, MemoryBank), including an explicit note on which numbers
  (confirmation thresholds, success-rate cutoffs) are this system's own
  operational decisions and not derived from any cited source.
- Added a metadata header (`type`, `version`, `date`, `supersedes`) to
  both article files, so future edits to the idea don't silently
  overwrite this version; they get their own version number and link
  back here instead.
- Removed em dashes from all article and repo text per a standing
  preference, replaced with commas or plain hyphens.

## v0.2, feat: diagrams, source-grounded thresholds, MemoryBus section

- supersedes: `articles/cortex-memory-architecture/v0.1`

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
  minimum 3 independent confirmations, average confidence >= 0.80.
- Replaced the generic procedural-memory description with the real
  auto-activation rule from `ProceduralMemory`: sample size >= 20 and
  success rate >= 0.85 leads to automatic activation, but only for
  low-impact procedures; high-impact ones go to `pending_approval`
  regardless of the numbers.
- Added the "never delete, always supersede" governance rule
  (`SemanticMemory::supersede()`), which the original draft didn't
  mention at all, arguably the single most defensible technical decision
  in the whole architecture, and was previously left out.
- Added a short section on `MemoryBus` as the single access facade,
  including *why* it exists (testability via `MemoryBusInterface`,
  since `MemoryBus` itself is `final`) and *what it buys operationally*
  (per-layer failure isolation via individual try/catch blocks, so an
  episodic-memory outage doesn't take down the whole agent).

**Tone**
- Tightened repetitive phrasing from the AI-assisted first pass
  ("Fiz com tom mais humano..." framing removed entirely, that was an
  artifact of the editing process, not part of the article).
- Adjusted vocabulary and phrasing toward Portuguese as spoken/written in
  Angola rather than generic PT-PT/PT-BR defaults.

## v0.1, doc: first draft

Initial version: four-layer description in prose, no diagrams, no
source-grounded thresholds (validation criteria and activation rules were
described qualitatively rather than with the actual numbers from the code).
