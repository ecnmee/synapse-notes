<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Monitor de saúde de procedimentos activos.
 *
 * Responsabilidade: identificar procedimentos 'active' que degradaram
 * abaixo do threshold mínimo de qualidade e desactivá-los via
 * {@see ProceduralMemory::deactivate()}.
 *
 * ─── Critério de desactivação ────────────────────────────────────────────────
 *
 * Um procedimento é desactivado quando:
 *   status = 'active'
 *   AND sample_size >= MIN_SAMPLE_SIZE   — evidência mínima para decisão
 *   AND success_rate < DEGRADATION_THRESHOLD
 *
 * MIN_SAMPLE_SIZE protege procedimentos recém-activados de serem desactivados
 * prematuramente por uma sequência inicial de falhas.
 *
 * ─── Relação com ProceduralMemory::deactivate() ──────────────────────────────
 *
 * deactivate() já existe — marca status='deprecated'.
 * O monitor é o componente que decide quando chamar deactivate(),
 * com base em critérios estatísticos. São responsabilidades separadas.
 *
 * ─── Versão v1 (threshold absoluto) ─────────────────────────────────────────
 *
 * Esta implementação usa threshold absoluto — não detecta tendência.
 * Um procedimento estável em 58% é desactivado da mesma forma que um
 * em queda de 90% para 58%. A distinção por tendência pertence a v2,
 * quando existir histórico de snapshots em agent_procedure_snapshots.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class ProceduralHealthMonitor
{
    /**
     * Taxa mínima de sucesso para manter um procedimento activo.
     * Abaixo deste valor, o procedimento é desactivado.
     */
    private const DEGRADATION_THRESHOLD = 0.60;

    /**
     * Mínimo de execuções antes de avaliar degradação.
     * Protege procedimentos recém-activados de desactivação prematura.
     */
    private const MIN_SAMPLE_SIZE = 30;

    /**
     * Número de dias após activação durante os quais o procedimento é imune
     * a desactivação — permite estabilização das métricas iniciais.
     */
    private const GRACE_PERIOD_DAYS = 7;

    private const TABLE = 'agent_procedures';

    public function __construct(
        private readonly ProceduralMemoryInterface $proceduralMemory,
    ) {}

    /**
     * Avalia e desactiva procedimentos degradados de um tenant.
     *
     * @param  int $tenantId
     * @return int Número de procedimentos desactivados nesta chamada.
     */
    public function monitor(int $tenantId): int
    {
        $deactivated = 0;

        try {
            $degraded = $this->fetchDegradedProcedures($tenantId);
        } catch (\Exception $e) {
            Log::error('[ProceduralHealthMonitor] Falha ao carregar procedimentos.', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return 0;
        }

        foreach ($degraded as $procedure) {
            try {
                $this->proceduralMemory->deactivate($tenantId, $procedure->trigger);
                $deactivated++;

                Log::info('[ProceduralHealthMonitor] Procedimento desactivado.', [
                    'tenant_id'    => $tenantId,
                    'trigger'      => $procedure->trigger,
                    'success_rate' => $procedure->success_rate,
                    'sample_size'  => $procedure->sample_size,
                ]);
            } catch (\Exception $e) {
                Log::warning('[ProceduralHealthMonitor] Falha ao desactivar procedimento.', [
                    'error'     => $e->getMessage(),
                    'tenant_id' => $tenantId,
                    'trigger'   => $procedure->trigger,
                ]);
            }
        }

        return $deactivated;
    }

    /**
     * Carrega procedimentos activos com success_rate abaixo do threshold.
     *
     * @return list<object{trigger: string, success_rate: float, sample_size: int}>
     */
    private function fetchDegradedProcedures(int $tenantId): array
    {
        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('version', 'v2')
            ->where('sample_size', '>=', self::MIN_SAMPLE_SIZE)
            ->where('success_rate', '<', self::DEGRADATION_THRESHOLD)
            ->where('updated_at', '<', now()->subDays(self::GRACE_PERIOD_DAYS))
            ->get(['trigger', 'success_rate', 'sample_size'])
            ->all();
    }
}
