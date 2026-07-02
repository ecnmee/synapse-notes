# References, cortex-memory-architecture

Sources that back (or directly inspired) the claims made in this article.
Where an idea in CortexOS is a direct application of prior work, that is
noted; where it diverges, that is also noted.

## Retrieval-Augmented Generation (the baseline this article argues beyond)

- Lewis, P. et al. (2020). *Retrieval-Augmented Generation for
  Knowledge-Intensive NLP Tasks*. NeurIPS.
  https://arxiv.org/abs/2005.11401
  - Origin of the RAG pattern referenced in the "dominant pattern" section.

## Cognitive architectures for language agents (direct inspiration)

- Sumers, T. et al. (2023). *Cognitive Architectures for Language Agents*
  (CoALA). arXiv:2309.02427.
  https://arxiv.org/abs/2309.02427
  - The working / episodic / semantic / procedural split used in CortexOS
    follows the memory taxonomy proposed in this paper, applied to a PHP
    system in production, not a research prototype.

- Anderson, J. R. (2007). *How Can the Human Mind Occur in the Physical
  Universe?* Oxford University Press. (ACT-R)
  https://act-r.psy.cmu.edu/
  - Classic cognitive architecture distinguishing declarative memory
    (similar to semantic) from procedural memory; CortexOS's
    candidate-to-active pipeline is a simplified, loose analogue of
    ACT-R's procedural learning, not a faithful implementation.

- Laird, J. E. (2012). *The Soar Cognitive Architecture*. MIT Press.
  https://soar.eecs.umich.edu/
  - Another classic reference for procedural/operator-based learning;
    cited as context, not implemented directly.

## Agent memory in LLM systems (closer to current practice)

- Park, J. S. et al. (2023). *Generative Agents: Interactive Simulacra of
  Human Behavior*. arXiv:2304.03442.
  https://arxiv.org/abs/2304.03442
  - Memory stream + reflection pattern; relevant comparison point for the
    episodic layer and the Learner/reflection cycle described here.

- Packer, C. et al. (2023). *MemGPT: Towards LLMs as Operating Systems*.
  arXiv:2310.08560.
  https://arxiv.org/abs/2310.08560
  - OS-inspired memory paging for LLM agents; comparable in spirit to the
    Working Memory layer's cache/TTL model, though MemGPT's mechanism
    (paging in/out of context) differs from CortexOS's approach (separate
    persistent layers behind a bus).

- Zhong, W. et al. (2023). *MemoryBank: Enhancing Large Language Models
  with Long-Term Memory*. arXiv:2305.10250.
  https://arxiv.org/abs/2305.10250
  - Forgetting-curve-inspired memory update mechanism; comparison point
    for procedural memory degradation. Since P5.1, CortexOS has a
    `ProceduralHealthMonitor` that deactivates procedures whose
    `success_rate` drops below an absolute threshold (0.60), with a
    minimum sample size and a grace period, rather than gradually
    decaying confidence as in MemoryBank. It is a threshold-based
    deactivation mechanism, not a forgetting curve.

## Where CortexOS diverges from the literature

- None of the cited cognitive architectures (ACT-R, SOAR) or LLM-memory
  papers (Generative Agents, MemGPT, MemoryBank) define the specific
  promotion thresholds used here (3 confirmations, 0.80 average confidence
  for semantic memory; minimum 20 samples and 0.85 success rate for
  procedural memory). Those numbers are operational decisions made for
  this specific production system, not values derived from any paper, and
  are flagged as such in the article itself.
- The "never delete, always supersede" rule (event-sourcing-style
  governance) is a software-engineering decision borrowed from general
  event-sourcing and audit-log practice, not from the cognitive
  architecture literature specifically.
