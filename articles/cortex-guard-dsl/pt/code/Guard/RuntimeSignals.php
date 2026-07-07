<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Guard;

/**
 * Flags booleanas transitórias da iteração corrente da FSM.
 *
 * Representa sinais derivados — eventos que ocorreram durante a iteração
 * e que determinam qual transição é válida. Não é persistido. Vive apenas
 * durante a avaliação da transição corrente.
 *
 * Contadores e métricas acumuladas pertencem ao {@see \App\Services\V2\Agent\Kernel\ExecutionMetrics},
 * que é persistido dentro do payload do {@see \App\Services\V2\Agent\Kernel\AgentContext}.
 *
 * Todos os campos têm default `false`. Um {@see self::none()} representa
 * uma iteração sem sinais activos (ex: OBSERVE → REFLECT).
 *
 * @package App\Services\V2\Agent\Guard
 * @author  Eduardo Costa Nkuansambu
 */
final class RuntimeSignals
{
    public function __construct(
        // ─── Policy ──────────────────────────────────────────────────────────
        public readonly bool $policyPermitted            = false,
        public readonly bool $policyDenied               = false,

        // ─── Plan ────────────────────────────────────────────────────────────
        public readonly bool $planHasSelectedTool        = false,

        // ─── Tool result ─────────────────────────────────────────────────────
        public readonly bool $toolResultReceived         = false,
        public readonly bool $toolResultRequestsHandoff  = false,

        // ─── Goals ───────────────────────────────────────────────────────────
        public readonly bool $goalsAllResolved           = false,

        // ─── Operator ────────────────────────────────────────────────────────
        public readonly bool $operatorAccepted           = false,
        public readonly bool $operatorReplied            = false,
        public readonly bool $operatorAssumedFullControl = false,

        // ─── Timer ───────────────────────────────────────────────────────────
        public readonly bool $timerExpired               = false,

        // ─── Effects ─────────────────────────────────────────────────────────
        public readonly bool $allCriticalEffectsCommitted = false,
    ) {}

    /**
     * Nenhum sinal activo — usado em transições que não produzem sinais.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Cria uma nova instância com os campos alterados.
     *
     * @param array<string, bool> $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            policyPermitted:            $overrides['policyPermitted']            ?? $this->policyPermitted,
            policyDenied:               $overrides['policyDenied']               ?? $this->policyDenied,
            planHasSelectedTool:        $overrides['planHasSelectedTool']        ?? $this->planHasSelectedTool,
            toolResultReceived:         $overrides['toolResultReceived']         ?? $this->toolResultReceived,
            toolResultRequestsHandoff:  $overrides['toolResultRequestsHandoff']  ?? $this->toolResultRequestsHandoff,
            goalsAllResolved:           $overrides['goalsAllResolved']           ?? $this->goalsAllResolved,
            operatorAccepted:           $overrides['operatorAccepted']           ?? $this->operatorAccepted,
            operatorReplied:            $overrides['operatorReplied']            ?? $this->operatorReplied,
            operatorAssumedFullControl: $overrides['operatorAssumedFullControl'] ?? $this->operatorAssumedFullControl,
            timerExpired:               $overrides['timerExpired']               ?? $this->timerExpired,
            allCriticalEffectsCommitted: $overrides['allCriticalEffectsCommitted'] ?? $this->allCriticalEffectsCommitted,
        );
    }
}
