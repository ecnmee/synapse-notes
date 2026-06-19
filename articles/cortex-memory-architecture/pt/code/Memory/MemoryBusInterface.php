<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use App\Services\V2\Agent\Kernel\AgentMemorySummary;

/**
 * Contrato de acesso ao bus de memória do CortexOS.
 *
 * Extraído de {@see MemoryBus} para permitir substituição em testes
 * sem depender de Reflection ou de Mockery com extensões adicionais.
 *
 * ─── Por que esta interface existe ───────────────────────────────────────────
 *
 * MemoryBus é `final` por desenho — o mecanismo de extensão correcto é
 * implementar esta interface de raiz, não subclassar MemoryBus.
 * Qualquer consumidor que dependa de MemoryBus directamente é impossível
 * de isolar em testes unitários sem tocar nas suas dependências concretas
 * (WorkingMemory, EpisodicMemory, etc.), que por sua vez dependem de
 * facades Laravel.
 *
 * Ao depender desta interface, os consumidores ficam isoláveis com um
 * createMock() simples.
 *
 * ─── Métodos incluídos ───────────────────────────────────────────────────────
 *
 * Apenas os métodos usados por consumidores directos da factory e do agente.
 * Métodos de acesso interno (working(), procedural()) não fazem parte do
 * contrato público — permanecem em MemoryBus como implementação concreta.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
interface MemoryBusInterface
{
    /**
     * Carrega um resumo das camadas relevantes para o turno actual.
     *
     * @param  int    $tenantId
     * @param  string $sessionId
     * @param  string $query     Query do utilizador para busca semântica relevante.
     * @return AgentMemorySummary
     */
    public function load(int $tenantId, string $sessionId, string $query): AgentMemorySummary;

    /**
     * Persiste actualizações de working memory após um turno.
     *
     * @param  int                   $tenantId
     * @param  string                $sessionId
     * @param  array<string, mixed>  $updates
     */
    public function updateWorking(int $tenantId, string $sessionId, array $updates): void;

    /**
     * Persiste um novo episódio após execução completa (CLOSED).
     *
     * @param  int                  $tenantId
     * @param  string               $sessionId
     * @param  array<string, mixed> $episode
     */
    public function persistEpisode(int $tenantId, string $sessionId, array $episode): void;

    /**
     * Propõe um novo facto semântico para validação.
     *
     * @param  int    $tenantId
     * @param  string $entity
     * @param  string $claim
     * @param  float  $confidence
     * @param  string $source
     */
    public function proposeSemantic(
        int    $tenantId,
        string $entity,
        string $claim,
        float  $confidence,
        string $source,
    ): void;

    /**
     * Propõe um novo procedimento para o pipeline de validação do Learner.
     *
     * @param  int           $tenantId
     * @param  string        $trigger
     * @param  list<string>  $workflow
     * @param  string        $impactLevel 'low'|'high'
     */
    public function proposeProcedure(
        int    $tenantId,
        string $trigger,
        array  $workflow,
        string $impactLevel = 'low',
    ): void;
}
