# code/, excertos de schema e implementação que sustentam este artigo

Implementação real (anonimizada onde necessário) das 4 camadas de memória
e as migrations correspondentes. Reflecte o estado actual do CortexOS
(P5.1), que evoluiu depois da publicação inicial do artigo (v0.6). Ver
`CHANGELOG.md` e a nota de evolução em `pt/article.md` para o que mudou
desde a publicação.

## Memory/

Implementação concreta das 4 camadas e da fachada que as une:

| Ficheiro | Camada / papel |
|---|---|
| `MemoryBus.php`, `MemoryBusInterface.php` | A fachada única citada na secção "Tudo passa por um único ponto". |
| `WorkingMemory.php`, `WorkingMemoryInterface.php` | Working Memory: cache com TTL, merge incremental. |
| `EpisodicMemory.php`, `EpisodicMemoryInterface.php` | Pipeline legacy de compressão assíncrona (`agent_episodes`): resumo + embedding via serviço Python. |
| `SemanticMemory.php`, `SemanticValidator.php` | Semantic Memory: pipeline `propose()` → `validate()`, os limiares de 3 confirmações e confiança 0.80 citados no artigo. |
| `SemanticConflictResolver.php`, `ConflictResolution.php` | **Novo (P5.1).** Resolve conflitos entre factos activos para a mesma entity: candidato com confiança maior substitui via `supersede()`; candidato com confiança menor é rejeitado. Chamado pelo `SemanticValidator` antes de promover. |
| `ProceduralMemory.php`, `ProceduralMemoryInterface.php` | Procedural Memory: `propose()`, `recordOutcome()`. Pipeline simplificado: `candidate → scored → active|pending_approval` (o estado `validated` foi removido, era um estado fantasma sem componente a operá-lo). `bootstrapCandidate()` semeia procedimentos com métricas históricas vindas do PatternDetector. |
| `ProceduralHealthMonitor.php` | **Novo.** Desactiva procedimentos `active` que degradaram: `success_rate < 0.60`, com amostra mínima de 30 execuções e um período de graça de 7 dias após activação. Fecha o ciclo de vida que o artigo, na versão publicada, descrevia como "ainda não implementado" (ver `REFERENCES.md`). |
| `Episodic/Episode.php`, `EpisodeOutcome.php` | Modelo de domínio do episódio (VO imutável). |
| `Episodic/EpisodeRepository.php`, `EpisodeRepositoryInterface.php` | Persiste em `agent_domain_episodes` (episódios de domínio imutáveis), distinta da tabela legacy `agent_episodes` usada por `EpisodicMemory`. `find()` agora exige `tenant_id` explícito. |
| `Episodic/EpisodeToolTraceRepository.php` | **Novo.** Persiste a telemetria de tool calls em `agent_episode_tool_traces`, separada do episódio por volume; é a fonte da query do PatternDetector citada no artigo. |
| `Policy/PolicyObservationRepository.php` | Telemetria de política, mencionada como "sem pipeline candidate/active" na secção do `MemoryBus`. |

## migrations/

| Ficheiro | O que prova no artigo |
|---|---|
| `2026_01_01_000003_create_agent_episodes_table.php` | Schema da camada Episodic legacy: `status` default `pending_compression`, `tool_trace` e `alignment_traces` já incluídos na criação da tabela (a migration `add_tool_trace` separada foi descontinuada e absorvida aqui). |
| `2026_01_01_000004_create_agent_semantic_memory_table.php` | Schema da camada Semantic, agora com o índice `idx_semantic_tenant_entity_status`, necessário para a query do `SemanticConflictResolver`. |
| `2026_01_01_000006_create_agent_procedures_table.php` | Schema da camada Procedural: pipeline `candidate → scored → active|pending_approval`, sem o estado `validated`. |
| `2026_01_01_000007_create_agent_episode_tool_traces_table.php` | Telemetria por tool execution; a query do `PatternDetector` (`HAVING COUNT(*) >= 20 AND AVG(success) >= 0.85`) é exactamente o limiar citado na secção de Procedural Memory. |
| `2026_06_18_000001_create_agent_policy_observations_table.php` | Schema da telemetria de política mencionada na secção do `MemoryBus`. |
| `2026_06_20_000001_create_agent_domain_episodes_table.php` | **Novo.** Schema dos episódios de domínio imutáveis (`EpisodeRepository`), incluindo o campo `compacted_at` para o futuro `EpisodeCompactionJob` (P5.3, ainda não documentado neste artigo). |

## O que ficou de fora, e porquê

Ficheiros de outros subsistemas do CortexOS (núcleo da FSM, Reflection
Tier 2, alinhamento estratégico, pipeline de Knowledge Base, e as suas
excepções) continuam fora desta pasta, por pertencerem a artigos
futuros: `cortex-fsm-kernel`, `cortex-reflection-engine`,
`cortex-strategic-alignment`, `cortex-kb-pipeline`.
