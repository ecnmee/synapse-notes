---
type: feat
version: v0.3
date: 2026-06-19
supersedes: none
lang: en
companion: ./article.pt-AO.md
references: ./REFERENCES.md
---

# The 4 Memory Layers That Could Change How We Build AI Agents

*[Ler em português de Angola](./article.pt-AO.md)*

Hey, how's it going?

I was recently hired to build **Simplifika AI**, a customer-support
assistant powered by AI. The goal, on the surface, is simple: help
customers resolve questions using the company's own knowledge. But the
deeper I got into the project, the clearer it became that there was room
to go well beyond the usual approach.

That's how **CortexOS** started, the kernel behind it: a cognitive agent
built in PHP, using a Finite State Machine (FSM), layered reflection,
intelligent routing, and, most importantly, a memory architecture
designed for real evolution, not just for "remembering things."

I'm not trying to sell the project here. I want to share an idea I think
matters for anyone building agents and chatbots: **how to design memory
that's actually good**, instead of relying only on huge prompts and
vector search.

## The dominant pattern, and where it breaks down

Today, most agents and chatbots follow more or less the same model:

- **Prompt stuffing**: cram as much context as possible into the model's
  context window.
- **RAG (Retrieval-Augmented Generation)**: use a vector store to fetch
  relevant documents and inject them into the prompt.

This pattern became dominant after the 2020 RAG paper (Lewis et al.,
Meta AI), which solved a real problem (LLMs have no access to private
data). But over time it shows real limits:

- The agent doesn't actually "learn"; it only retrieves what's already
  there.
- Every new conversation burns tokens repeating the same context.
- Knowledge doesn't accumulate cleanly over time.
- Mistakes repeat, because there's no structured validation of what was
  "learned."

For Simplifika, I wanted something different.

## The proposal: four memory layers, each with a defined role

Instead of "context + RAG", I structured CortexOS's memory into **four
layers**, each with a clear responsibility and a different persistence
model, loosely inspired by cognitive architectures for language agents
(see [CoALA](https://arxiv.org/abs/2309.02427) in the references).

![The four memory layers of CortexOS](./diagrams/01-four-layers.svg)

### 1. Working Memory, the agent's RAM

This is the conversation's immediate state: active entities, the last
tool result, temporary notes. It lives in cache with a 1-hour TTL, and
updates are merged, never a full replacement of the session state. It's
fast and deliberately volatile: once the session closes, there's no
reason to keep it forever.

### 2. Episodic Memory, past episodes lived through

This holds compressed summaries of past conversations, searchable by
semantic similarity via embeddings. CortexOS doesn't store the whole
conversation; it stores just enough to recognize "I've seen something
like this before." Compression happens asynchronously, off the critical
execution path: an episode enters with `pending_compression` status and
is only processed later by a dedicated job.

### 3. Semantic Memory, consolidated facts

This is the layer for validated knowledge about the business. Things
like "the maximum delivery time is 5 business days" or "VAT in Angola is
14%." And here's the important part: **this isn't just vector search.**
There's an actual pipeline:

1. The Learner observes executions and proposes **candidates**; each new
   proposal for the same fact counts as an independent confirmation.
2. A validator (`SemanticValidator`) only promotes a candidate to
   **active** once it shows up in at least **3 distinct confirmations**,
   with an average confidence of at least **0.80**.
3. An active fact is never deleted or edited directly. Correcting it
   creates a new record, marking the old one as `superseded`; the full
   history stays auditable.

This "never delete, only supersede" rule is treated as a governance
invariant in the system: every knowledge decision leaves a trail.

### 4. Procedural Memory, my favorite layer

This holds **sequences of actions that already worked**. For example:
"when a customer asks about an overdue invoice, workflow X resolves it
most of the time." Each procedure accumulates a success rate
(`success_rate`) and a sample size (`sample_size`).

Once a procedure reaches **at least 20 observed executions** and a
success rate of **85% or higher**, it gets promoted automatically to
`active`, but only if it's classified as low impact. High-impact
procedures, even with good numbers, go to `pending_approval`: a human
has to sign off before the agent starts executing them without calling
the LLM.

## Everything goes through one single point: the MemoryBus

No part of the agent touches a memory layer directly. All reads and
writes go through a single facade, the `MemoryBus`.

![MemoryBus as the single access facade](./diagrams/02-memory-bus-facade.svg)

This brings two practical advantages that only became obvious after
testing the system in practice:

- **Testability.** Since `MemoryBus` is `final`, any consumer depending
  on it directly would be impossible to isolate in a unit test without
  touching its concrete dependencies (cache, database, Python API). That's
  why an interface (`MemoryBusInterface`) exists: consumers only know the
  contract, never the implementation.
- **Isolated failures don't take down the agent.** Each method on the bus
  has its own try/catch. If the episodic layer fails (say, the Python
  service is down), the agent keeps running with empty memory for that
  layer, instead of failing the whole execution.

## The cycle that actually makes the difference

Having four separate layers, on its own, doesn't solve anything. What
matters is the full cycle:

![The learning cycle: execution → traces → Learner → validation → promotion](./diagrams/03-learning-cycle.svg)

Agent executes → generates traces (episodes, tool results) → the Learner
analyzes them and produces candidates → the validator promotes whatever
is reliable enough → memory gets updated → the next conversation already
uses what was learned, instead of rediscovering the same thing again.

Over time, the agent gets faster, cheaper, and more consistent, because
a growing share of decisions stops depending on a call to the LLM.

## What's the actual difference?

Most agent frameworks today focus on **retrieving** information.
CortexOS focuses on **accumulating and validating** knowledge, and treats
that as a governance pipeline, not an implementation detail.

Few teams have something like this openly documented today. Anthropic
and OpenAI are exploring long-term memory in agents, but in practice most
enterprise solutions still live entirely in the RAG + big-prompt model.
Companies like Adept, Imbue, and a handful of autonomous-agent startups
are heading in this direction, but it's still mostly unexplored territory
in production.

## For anyone building agents

If you're building an agent or a chatbot, it's worth asking:

- How much of your agent actually learns over time, instead of just
  retrieving?
- How much does it still depend on calling the LLM on nearly every
  interaction?
- Do you have an explicit criterion for validating what it "learns", or
  are you trusting the first pattern that shows up?

Memory isn't the most glamorous part of an agent. But it's probably the
one that weighs the most on long-term quality.

## References

This article builds on published work, it doesn't invent the concepts
from scratch:

- Lewis, P. et al. (2020). *Retrieval-Augmented Generation for Knowledge-Intensive NLP Tasks*. NeurIPS. https://arxiv.org/abs/2005.11401
- Sumers, T. et al. (2023). *Cognitive Architectures for Language Agents* (CoALA). arXiv:2309.02427. https://arxiv.org/abs/2309.02427
- Anderson, J. R. (2007). *How Can the Human Mind Occur in the Physical Universe?* Oxford University Press (ACT-R). https://act-r.psy.cmu.edu/
- Laird, J. E. (2012). *The Soar Cognitive Architecture*. MIT Press. https://soar.eecs.umich.edu/
- Park, J. S. et al. (2023). *Generative Agents: Interactive Simulacra of Human Behavior*. arXiv:2304.03442. https://arxiv.org/abs/2304.03442
- Packer, C. et al. (2023). *MemGPT: Towards LLMs as Operating Systems*. arXiv:2310.08560. https://arxiv.org/abs/2310.08560
- Zhong, W. et al. (2023). *MemoryBank: Enhancing Large Language Models with Long-Term Memory*. arXiv:2305.10250. https://arxiv.org/abs/2305.10250

Full list, with notes on where CortexOS follows the literature and where
it diverges, in [`REFERENCES.md`](./REFERENCES.md).

---

What about you? What's been your biggest challenge building memory into
your agents? Have you tried separating procedural memory from semantic
memory?

Leave a comment, I'll read and reply.
