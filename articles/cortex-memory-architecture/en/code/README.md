# code/ - schema excerpts and implementation backing this article

Real implementation (anonymized where necessary) of the four memory
layers and the corresponding migrations. Reflects the current CortexOS
state (P5.1). See the post-publication note in `en/article.md` and
`CHANGELOG.md` for what changed since the article was first published.

## Memory/

| File | Layer / role |
|---|---|
| `MemoryBus.php`, `MemoryBusInterface.php` | The single access facade described in "Everything goes through one single point". |
| `WorkingMemory.php`, `WorkingMemoryInterface.php` | Working Memory: cache with TTL, incremental merge. |
| `EpisodicMemory.php`, `EpisodicMemoryInterface.php` | Legacy async compression pipeline (`agent_episodes`): summary + embedding via the Python service. |
| `SemanticMemory.php`, `SemanticValidator.php` | Semantic Memory: `propose()` -> `validate()` pipeline, the 3-confirmation and 0.80 confidence thresholds cited in the article. |
| `SemanticConflictResolver.php`, `ConflictResolution.php` | **New (P5.1).** Resolves conflicts between active facts for the same entity: higher-confidence candidate supersedes the existing one via `supersede()`; lower-confidence candidate is rejected. Called by `SemanticValidator` before promoting. |
| `ProceduralMemory.php`, `ProceduralMemoryInterface.php` | Procedural Memory: `propose()`, `recordOutcome()`, `bootstrapCandidate()`. Simplified pipeline: `candidate -> scored -> active\|pending_approval` (the `validated` ghost state was removed). |
| `ProceduralHealthMonitor.php` | **New.** Deactivates `active` procedures that degraded: `success_rate < 0.60`, minimum sample of 30 executions, 7-day grace period after activation. Closes the lifecycle loop. |
| `Episodic/Episode.php`, `EpisodeOutcome.php` | Immutable domain episode value object. |
| `Episodic/EpisodeRepository.php`, `EpisodeRepositoryInterface.php` | Persists to `agent_domain_episodes`. `find()` requires an explicit `tenant_id`. |
| `Episodic/EpisodeToolTraceRepository.php` | **New.** Read-only access to `agent_episode_tool_traces`, the source of the PatternDetector query cited in the article. |
| `Policy/PolicyObservationRepository.php` | Policy telemetry, described as "no candidate/active pipeline" in the MemoryBus section. |

## migrations/

| File | What it proves in the article |
|---|---|
| `2026_01_01_000003_create_agent_episodes_table.php` | Episodic layer schema: `pending_compression` default status, `tool_trace` and `alignment_traces` included at table creation. |
| `2026_01_01_000004_create_agent_semantic_memory_table.php` | Semantic layer schema with `idx_semantic_tenant_entity_status` index required by `SemanticConflictResolver`. |
| `2026_01_01_000006_create_agent_procedures_table.php` | Procedural layer schema: pipeline `candidate -> scored -> active\|pending_approval`, no `validated` state. |
| `2026_01_01_000007_create_agent_episode_tool_traces_table.php` | Tool execution telemetry; the PatternDetector query (`HAVING COUNT(*) >= 20 AND AVG(success) >= 0.85`) matches exactly the threshold cited in the article. |
| `2026_06_18_000001_create_agent_policy_observations_table.php` | Policy telemetry schema mentioned in the MemoryBus section. |
| `2026_06_20_000001_create_agent_domain_episodes_table.php` | Immutable domain episodes schema, including `compacted_at` for the future `EpisodeCompactionJob` (P5.3, not yet documented in this article). |

## What was intentionally left out

Files from other CortexOS subsystems (FSM core, Reflection Tier 2,
strategic alignment, Knowledge Base pipeline, and their exceptions) are
not here. They belong to future articles: `cortex-fsm-kernel`,
`cortex-reflection-engine`, `cortex-strategic-alignment`,
`cortex-kb-pipeline`.
