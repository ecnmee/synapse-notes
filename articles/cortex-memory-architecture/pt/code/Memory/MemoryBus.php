<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use App\Services\V2\Agent\Kernel\AgentMemorySummary;
use App\Services\V2\Agent\Memory\Policy\PolicyObservationRepository;
use Illuminate\Support\Facades\Log;

/**
 * Fachada única de acesso às 4 camadas de memória do CortexOS.
 *
 * Nenhum componente acede directamente a uma camada de memória.
 * Todo o acesso passa por este bus, que coordena leitura, escrita
 * e consolidação entre camadas.
 *
 * As 4 camadas:
 *   - Working    → contexto imediato da sessão
 *   - Episodic   → experiências passadas comprimidas
 *   - Semantic   → factos consolidados sobre o negócio
 *   - Procedural → workflows executáveis com success_rate
 *
 * Telemetria operacional:
 *   - Policy     → observações de política via {@see PolicyObservationRepository}
 *                  (sem pipeline candidate/active — persistência directa)
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class MemoryBus implements MemoryBusInterface
{
    public function __construct(
        private readonly WorkingMemoryInterface       $working,
        private readonly EpisodicMemoryInterface      $episodic,
        private readonly SemanticMemory               $semantic,
        private readonly ProceduralMemoryInterface    $procedural,
        private readonly PolicyObservationRepository  $policyObservations,
    ) {}

    /**
     * Carrega um resumo das camadas relevantes para o turno actual.
     *
     * Não carrega a memória completa — extrai apenas o necessário
     * para construir o {@see AgentMemorySummary} do snapshot.
     *
     * @param  int    $tenantId
     * @param  string $sessionId
     * @param  string $query     Query do utilizador para busca semântica relevante.
     * @return AgentMemorySummary
     */
    public function load(int $tenantId, string $sessionId, string $query): AgentMemorySummary
    {
        try {
            return new AgentMemorySummary(
                working:    $this->working->load($tenantId, $sessionId),
                episodic:   $this->episodic->loadRelevant($tenantId, $query, limit: 3),
                semantic:   $this->semantic->loadActive($tenantId, limit: 10),
                procedural: $this->procedural->loadActive($tenantId),
            );
        } catch (\Exception $e) {
            Log::warning('[MemoryBus] load falhou — devolvendo memória vazia', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
            ]);

            return new AgentMemorySummary();
        }
    }

    /**
     * Persiste actualizações de working memory após um turno.
     *
     * @param  int                   $tenantId
     * @param  string                $sessionId
     * @param  array<string, mixed>  $updates   Pares chave/valor a actualizar.
     */
    public function updateWorking(int $tenantId, string $sessionId, array $updates): void
    {
        try {
            $this->working->update($tenantId, $sessionId, $updates);
        } catch (\Exception $e) {
            Log::warning('[MemoryBus] updateWorking falhou', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
            ]);
        }
    }

    /**
     * Persiste um novo episódio após execução completa (CLOSED).
     *
     * Chamado pelo CognitiveMaintenance worker como DeferredEffect.
     *
     * @param  int                  $tenantId
     * @param  string               $sessionId
     * @param  array<string, mixed> $episode   Dados brutos do episódio a comprimir.
     */
    public function persistEpisode(int $tenantId, string $sessionId, array $episode): void
    {
        try {
            $this->episodic->persist($tenantId, $sessionId, $episode);
        } catch (\Exception $e) {
            Log::error('[MemoryBus] persistEpisode falhou', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
            ]);
        }
    }

    /**
     * Propõe um novo facto semântico para validação.
     *
     * O facto entra com status 'candidate' — só fica 'active' após
     * validação pelo SemanticMemoryValidator.
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
    ): void {
        try {
            $this->semantic->propose($tenantId, $entity, $claim, $confidence, $source);
        } catch (\Exception $e) {
            Log::warning('[MemoryBus] proposeSemantic falhou', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
                'entity'    => $entity,
            ]);
        }
    }

    /**
     * Persiste uma observação de política produzida pelo Learner.
     *
     * Observações de política são telemetria operacional — não conhecimento
     * validável. Por isso não existe pipeline candidate/active: a observação
     * é persistida directamente no {@see PolicyObservationRepository}.
     *
     * A assinatura recebe escalares — o MemoryBus não depende de objectos do
     * namespace Learner. O padrão segue {@see self::proposeSemantic()}.
     *
     * Falhas são registadas e silenciadas — o Learner é um subsistema não
     * crítico e uma falha de telemetria não deve bloquear o fluxo principal.
     *
     * @param  int                  $tenantId  Tenant de origem.
     * @param  string               $category  Tipo de observação (ex: "failure_recovery").
     * @param  string               $message   Descrição da observação.
     * @param  string               $severity  Nível: 'info' | 'warning' | 'critical'.
     * @param  string|null          $episodeId Episódio de origem, quando disponível.
     * @param  array<string, mixed> $metadata  Evidências adicionais estruturadas.
     */
    public function proposePolicy(
        int     $tenantId,
        string  $category,
        string  $message,
        string  $severity   = 'info',
        ?string $episodeId  = null,
        array   $metadata   = [],
    ): void {
        try {
            $this->policyObservations->store(
                tenantId:  $tenantId,
                category:  $category,
                message:   $message,
                severity:  $severity,
                episodeId: $episodeId,
                metadata:  $metadata,
            );
        } catch (\Exception $e) {
            Log::warning('[MemoryBus] proposePolicy falhou', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'category'   => $category,
                'episode_id' => $episodeId,
            ]);
        }
    }

    /**
     * Propõe um novo procedimento para o pipeline de validação do Learner.
     *
     * O procedimento entra com status 'candidate' — nunca activa directamente.
     *
     * @param  int                  $tenantId
     * @param  string               $trigger
     * @param  list<string>         $workflow   Sequência de nomes de tools.
     * @param  string               $impactLevel 'low'|'high'
     */
    public function proposeProcedure(
        int    $tenantId,
        string $trigger,
        array  $workflow,
        string $impactLevel = 'low',
    ): void {
        try {
            $this->procedural->propose($tenantId, $trigger, $workflow, $impactLevel);
        } catch (\Exception $e) {
            Log::warning('[MemoryBus] proposeProcedure falhou', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'trigger'    => $trigger,
            ]);
        }
    }

    /**
     * Regista o resultado de uma execução de procedimento.
     *
     * Fronteira obrigatória: nenhum componente da camada de aplicação
     * (ex: {@see \App\Services\V2\Agent\Application\ExecuteAgentService}) fala
     * directamente com {@see ProceduralMemory} — todo o acesso passa por aqui.
     *
     * ─── Porquê propose() + recordOutcome(), e não apenas recordOutcome() ──────
     *
     * {@see ProceduralMemory::recordOutcome()} apenas actualiza um procedimento
     * já existente: `if (! $procedure) { return; }` — sem log, sem erro. Sem
     * um candidato previamente proposto, recordOutcome() é uma no-op silenciosa
     * para qualquer trigger novo, e a aprendizagem procedimental nunca arranca.
     *
     * Por isso este método chama sempre {@see ProceduralMemory::propose()}
     * primeiro. propose() é idempotente por (tenant_id, trigger, version) —
     * devolve sem efeito se já existir candidate/scored/validated/active para
     * o trigger — por isso chamá-lo em toda execução é seguro: cria o
     * candidato na primeira ocorrência do trigger, e é uma no-op nas seguintes.
     * Depois, recordOutcome() actualiza sempre as métricas do procedimento
     * (recém-criado ou já existente).
     *
     * ─── impactLevel ─────────────────────────────────────────────────────────
     *
     * Fixado em 'low'. Não existe hoje nenhuma heurística aprovada para
     * derivar impact_level a partir do intent — inventar uma aqui seria
     * decidir, sem mandato, qual workflow é arriscado o suficiente para
     * exigir aprovação manual. Consequência directa: todo o procedimento
     * auto-proposto por este caminho fica elegível para activação automática
     * ({@see ProceduralMemory::AUTO_ACTIVATE_THRESHOLD}) ao atingir o sample
     * size mínimo. Revisar quando houver critério de classificação de risco.
     *
     * @param  int           $tenantId
     * @param  string        $trigger   Identificador da intenção (ex: $goal->metadata['intent']).
     * @param  list<string>  $workflow  Sequência de nomes de tools observada na execução.
     * @param  bool          $success   Se a execução resolveu pelo menos um goal.
     */
    public function recordProceduralOutcome(int $tenantId, string $trigger, array $workflow, bool $success): void
    {
        try {
            $this->procedural->propose($tenantId, $trigger, $workflow, impactLevel: 'low');
            $this->procedural->recordOutcome($tenantId, $trigger, $success);
        } catch (\Exception $e) {
            Log::warning('[MemoryBus] recordProceduralOutcome falhou', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'trigger'    => $trigger,
            ]);
        }
    }

    /**
     * Acesso directo à camada Working para operações de sessão.
     */
    public function working(): WorkingMemoryInterface
    {
        return $this->working;
    }

    /**
     * Acesso directo à camada Procedural para o ToolRouter (Layer 2).
     */
    public function procedural(): ProceduralMemoryInterface
    {
        return $this->procedural;
    }
}