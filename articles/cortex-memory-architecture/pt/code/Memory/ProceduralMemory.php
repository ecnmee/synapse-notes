<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Camada de Procedural Memory do CortexOS.
 *
 * Armazena workflows executáveis aprendidos pelo agente ao longo do tempo.
 * Cada procedimento descreve um padrão de resolução bem-sucedido: dado um
 * trigger (tipo de intenção), qual a sequência de tools a executar.
 *
 * Estrutura de um procedimento activo:
 * {
 *   trigger:      "billing_dispute",
 *   workflow:     ["knowledge_search", "llm_cognition"],
 *   success_rate: 0.91,
 *   sample_size:  47,
 *   impact_level: "low",
 *   status:       "active"
 * }
 *
 * Pipeline de activação (Invariante — nunca activa directamente):
 *   candidate → scored → validated → active (low impact, automático)
 *                                  → pending_approval (high impact, manual)
 *
 * Usado pelo {@see \App\Services\V2\Agent\Routing\ProceduralRouter} (Layer 2)
 * para selecção de tools sem custo de tokens.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class ProceduralMemory implements ProceduralMemoryInterface
{
    /**
     * Limiar mínimo de success_rate para que um procedimento seja elegível
     * para activação automática (impact_level = 'low').
     */
    private const AUTO_ACTIVATE_THRESHOLD = 0.85;

    /**
     * Tamanho mínimo de amostra antes de poder activar um procedimento.
     */
    private const MIN_SAMPLE_SIZE = 20;

    /**
     * Carrega todos os procedimentos activos do tenant.
     *
     * Retorna o formato que o {@see AgentMemorySummary::$procedural} espera,
     * compatível com o {@see \App\Services\V2\Agent\Routing\ProceduralRouter}.
     *
     * @param  int $tenantId
     * @return array<int, array{trigger: string, tool: string, workflow: list<string>, success_rate: float}>
     */
    public function loadActive(int $tenantId): array
    {
        try {
            return DB::table('agent_procedures')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->where('version', 'v2')
                ->orderByDesc('success_rate')
                ->get(['trigger', 'workflow', 'success_rate', 'sample_size'])
                ->map(function ($row) {
                    $workflow = is_string($row->workflow)
                        ? json_decode($row->workflow, true)
                        : $row->workflow;

                    return [
                        'trigger'      => $row->trigger,
                        'tool'         => $workflow[0] ?? '',
                        'workflow'     => $workflow ?? [],
                        'success_rate' => (float) $row->success_rate,
                        'sample_size'  => (int) $row->sample_size,
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::warning('[ProceduralMemory] loadActive falhou', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return [];
        }
    }

    /**
     * Propõe um novo procedimento com status 'candidate'.
     *
     * Nunca activa directamente — o pipeline de validação é obrigatório.
     *
     * @param  int           $tenantId
     * @param  string        $trigger      Identificador da intenção que activa este procedimento.
     * @param  list<string>  $workflow     Sequência de nomes de tools a executar.
     * @param  string        $impactLevel  'low'|'high'
     */
    public function propose(
        int    $tenantId,
        string $trigger,
        array  $workflow,
        string $impactLevel = 'low',
    ): void {
        try {
            $existing = DB::table('agent_procedures')
                ->where('tenant_id', $tenantId)
                ->where('trigger', $trigger)
                ->where('version', 'v2')
                ->whereIn('status', ['candidate', 'scored', 'validated', 'active'])
                ->first();

            if ($existing) {
                return;
            }

            DB::table('agent_procedures')->insert([
                'tenant_id'    => $tenantId,
                'trigger'      => $trigger,
                'workflow'     => json_encode($workflow),
                'success_rate' => 0.0,
                'sample_size'  => 0,
                'impact_level' => $impactLevel,
                'status'       => 'candidate',
                'version'      => 'v2',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('[ProceduralMemory] propose falhou', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'trigger'    => $trigger,
            ]);
        }
    }

    /**
     * Regista o resultado de uma execução de procedimento.
     *
     * Actualiza o success_rate e o sample_size do procedimento.
     * Se o procedimento atingir os critérios de activação automática
     * (impact_level = 'low', success_rate >= threshold, sample_size >= mínimo),
     * transita para 'active'. Se impact_level = 'high', transita para
     * 'pending_approval' para revisão humana.
     *
     * @param  int    $tenantId
     * @param  string $trigger
     * @param  bool   $success   Se a execução foi bem-sucedida.
     */
    public function recordOutcome(int $tenantId, string $trigger, bool $success): void
    {
        try {
            $procedure = DB::table('agent_procedures')
                ->where('tenant_id', $tenantId)
                ->where('trigger', $trigger)
                ->where('version', 'v2')
                ->whereIn('status', ['candidate', 'scored', 'validated', 'active'])
                ->first();

            if (! $procedure) {
                return;
            }

            $sampleSize  = (int) $procedure->sample_size + 1;
            $oldRate     = (float) $procedure->success_rate;
            $successRate = round(
                ($oldRate * ($sampleSize - 1) + ($success ? 1.0 : 0.0)) / $sampleSize,
                4
            );

            $newStatus = $procedure->status;

            if ($procedure->status !== 'active' && $sampleSize >= self::MIN_SAMPLE_SIZE) {
                if ($successRate >= self::AUTO_ACTIVATE_THRESHOLD) {
                    $newStatus = $procedure->impact_level === 'low'
                        ? 'active'
                        : 'pending_approval';
                } else {
                    $newStatus = 'scored';
                }
            }

            DB::table('agent_procedures')
                ->where('id', $procedure->id)
                ->update([
                    'success_rate' => $successRate,
                    'sample_size'  => $sampleSize,
                    'status'       => $newStatus,
                    'updated_at'   => now(),
                ]);

        } catch (\Exception $e) {
            Log::warning('[ProceduralMemory] recordOutcome falhou', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'trigger'    => $trigger,
            ]);
        }
    }

    /**
     * Desactiva um procedimento que degradou abaixo do limiar.
     *
     * Usado pelo CognitiveMaintenance worker para limpar procedimentos obsoletos.
     *
     * @param  int    $tenantId
     * @param  string $trigger
     */
    public function deactivate(int $tenantId, string $trigger): void
    {
        try {
            DB::table('agent_procedures')
                ->where('tenant_id', $tenantId)
                ->where('trigger', $trigger)
                ->where('version', 'v2')
                ->update([
                    'status'     => 'deprecated',
                    'updated_at' => now(),
                ]);
        } catch (\Exception $e) {
            Log::warning('[ProceduralMemory] deactivate falhou', [
                'error'      => $e->getMessage(),
                'tenant_id'  => $tenantId,
                'trigger'    => $trigger,
            ]);
        }
    }
}
