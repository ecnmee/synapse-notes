# Referências, cortex-memory-architecture

Fontes que sustentam (ou inspiraram directamente) as afirmações feitas neste
artigo. Onde uma ideia do CortexOS é uma aplicação directa de trabalho
anterior, isso é assinalado; onde diverge, também.

## Retrieval-Augmented Generation (a base que este artigo argumenta superar)

- Lewis, P. et al. (2020). *Retrieval-Augmented Generation for
  Knowledge-Intensive NLP Tasks*. NeurIPS.
  https://arxiv.org/abs/2005.11401
  - Origem do padrão RAG referido na secção "padrão dominante".

## Arquitecturas cognitivas para agentes de linguagem (inspiração directa)

- Sumers, T. et al. (2023). *Cognitive Architectures for Language Agents*
  (CoALA). arXiv:2309.02427.
  https://arxiv.org/abs/2309.02427
  - A divisão working / episodic / semantic / procedural usada no CortexOS
    segue a taxonomia de memória proposta neste paper, aplicada a um
    sistema PHP em produção, não a um prototipo de investigação.

- Anderson, J. R. (2007). *How Can the Human Mind Occur in the Physical
  Universe?* Oxford University Press. (ACT-R)
  https://act-r.psy.cmu.edu/
  - Arquitectura cognitiva clássica que distingue memória declarativa
    (semelhante à semântica) de memória procedural; o pipeline
    candidato-para-activo do CortexOS é uma analogia simplificada e
    livre da aprendizagem procedural do ACT-R, não uma implementação
    fiel.

- Laird, J. E. (2012). *The Soar Cognitive Architecture*. MIT Press.
  https://soar.eecs.umich.edu/
  - Outra referência clássica para aprendizagem procedural/baseada em
    operadores; citada como contexto, não implementada directamente.

## Memória de agentes em sistemas LLM (mais próximo da prática actual)

- Park, J. S. et al. (2023). *Generative Agents: Interactive Simulacra of
  Human Behavior*. arXiv:2304.03442.
  https://arxiv.org/abs/2304.03442
  - Padrão de "memory stream" + reflexão; ponto de comparação relevante
    para a camada episódica e o ciclo Learner/reflexão descrito aqui.

- Packer, C. et al. (2023). *MemGPT: Towards LLMs as Operating Systems*.
  arXiv:2310.08560.
  https://arxiv.org/abs/2310.08560
  - Paginação de memória inspirada em sistemas operativos para agentes
    LLM; comparável em espírito ao modelo de cache/TTL da Working
    Memory, ainda que o mecanismo do MemGPT (paginar dentro/fora do
    contexto) seja diferente da abordagem do CortexOS (camadas
    persistentes separadas, atrás de um bus).

- Zhong, W. et al. (2023). *MemoryBank: Enhancing Large Language Models
  with Long-Term Memory*. arXiv:2305.10250.
  https://arxiv.org/abs/2305.10250
  - Mecanismo de actualização de memória inspirado na curva de
    esquecimento; citado como abordagem alternativa à degradação de
    memória que o CortexOS ainda não implementa (o CortexOS desactiva
    procedimentos explicitamente, em vez de os degradar
    automaticamente).

## Onde o CortexOS diverge da literatura

- Nenhuma das arquitecturas cognitivas citadas (ACT-R, SOAR) nem dos
  papers de memória LLM (Generative Agents, MemGPT, MemoryBank) define os
  limiares específicos de promoção usados aqui (3 confirmações, 0.80 de
  confiança média para a memória semântica; mínimo de 20 amostras e 0.85
  de taxa de sucesso para a memória procedural). Esses números são
  decisões operacionais tomadas para este sistema de produção específico,
  não valores derivados de nenhum paper, e isso é assinalado no próprio
  artigo.
- A regra "nunca apagar, sempre superseder" (governação ao estilo
  event-sourcing) é uma decisão de engenharia de software emprestada da
  prática geral de event-sourcing/auditoria, não da literatura de
  arquitecturas cognitivas especificamente.
