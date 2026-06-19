# code/ - excertos de schema e implementação que sustentam este artigo

Implementação real (anonimizada onde necessário) das 4 camadas de memória
e as migrations correspondentes. Cada ficheiro é referenciado explicitamente
no texto ou nos diagramas de `pt/article.md`.

## Memory/

Implementação concreta das 4 camadas e da fachada que as une:

| Ficheiro | Camada / papel |
|---|---|
| `MemoryBus.php`, `MemoryBusInterface.php` | A fachada única citada na secção "Tudo passa por um único ponto". |
| `WorkingMemory.php`, `WorkingMemoryInterface.php` | Working Memory: cache com TTL, merge incremental. |
| `EpisodicMemory.php`, `EpisodicMemoryInterface.php` | Episodic Memory: busca por similaridade, persistência assíncrona. |
| `SemanticMemory.php`, `SemanticValidator.php` | Semantic Memory: pipeline `propose()` → `validate()`, os limiares de 3 confirmações e confiança 0.80 citados no artigo. |
| `ProceduralMemory.php`, `ProceduralMemoryInterface.php` | Procedural Memory: `propose()`, `recordOutcome()`, activação automática em `sample_size >= 20` e `success_rate >= 0.85`. |
| `Episodic/Episode.php`, `EpisodeOutcome.php`, `EpisodeRepository.php`, `EpisodeRepositoryInterface.php` | Modelo de domínio do episódio e a sua persistência. |
| `Policy/PolicyObservationRepository.php` | Telemetria de política, mencionada como "sem pipeline candidate/active" na secção do `MemoryBus`. |

## migrations/

| Ficheiro | O que prova no artigo |
|---|---|
| `2026_01_01_000003_create_agent_episodes_table.php` | Schema da camada Episodic: `status` default `pending_compression`, `summary`/`embedding` preenchidos de forma assíncrona. |
| `2026_04_01_000001_add_tool_trace_to_agent_episodes_table.php` | Coluna `tool_trace` adicionada como nullable, compatível com episódios anteriores, suporta a afirmação de que episódios antigos não são invalidados. |
| `2026_04_01_000002_create_agent_episode_tool_traces_table.php` | Telemetria por tool execution; a query do `PatternDetector` documentada aqui (`HAVING COUNT(*) >= 20 AND AVG(success) >= 0.85`) é exactamente o limiar citado na secção de Procedural Memory. |
| `2026_01_01_000004_create_agent_semantic_memory_table.php` | Schema da camada Semantic: `status` (`candidate`/`active`/`superseded`) e `supersedes_fact_id`, que sustentam a invariante GOVERNANCE-5 ("nunca apagar, só substituir"). |
| `2026_01_01_000005_create_agent_procedures_table.php` | Schema da camada Procedural: `success_rate`, `sample_size`, `impact_level`, pipeline `candidate → scored → validated → active/pending_approval`. |
| `2026_06_18_115441_create_agent_policy_observations_table.php` | Schema da telemetria de política mencionada na secção do `MemoryBus`: tabela append-only, sem pipeline candidate/active, alinhada com a explicação de que observações de política não são "conhecimento validável". |

## O que ficou de fora, e porquê

Estes ficheiros foram enviados mas não pertencem a este artigo, são de
outros subsistemas do CortexOS e vão para as pastas de artigos futuros
correspondentes:

| Ficheiro | Subsistema | Artigo provável |
|---|---|---|
| `2026_01_01_000001_create_agent_executions_table.php` | Núcleo da FSM | `cortex-fsm-kernel` |
| `2026_01_01_000002_create_agent_execution_transitions_table.php` | Núcleo da FSM | `cortex-fsm-kernel` |
| `2026_06_13_000000_create_agent_reflections_table.php` | Reflection Tier 2 | `cortex-reflection-engine` |
| `2026_06_10_000001_create_agent_strategic_goal_metrics_table.php` | Alinhamento estratégico | `cortex-strategic-alignment` |
| `2026_02_04_210047_create_tenant_strategic_goals_table.php` | Alinhamento estratégico | `cortex-strategic-alignment` |
| `2026_01_01_000005_create_agent_kb_proposals_table.php` | Pipeline de Knowledge Base | `cortex-kb-pipeline` |
| `AllProvidersFailedException.php`, `ContextDeserializationException.php`, `CriticalEffectFailedException.php`, `ExecutionNotCompletedException.php`, `ExecutionNotFoundException.php`, `ExecutionSealedException.php` | Excepções do núcleo da FSM/execução | `cortex-fsm-kernel` |

Manter este artigo restrito ao que ele realmente afirma evita o problema
inverso de um repo "tudo numa pasta só": quem vier validar uma afirmação
específica encontra exactamente o ficheiro que a sustenta, não 18 ficheiros
de subsistemas diferentes.
