<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Guard;

/**
 * Constantes tipadas para os identificadores de threshold da FSM V2.
 *
 * Um threshold é um comparador numérico — nunca pode ser usado de forma
 * autónoma numa expressão de guarda. Requer sempre um operador e um valor
 * inteiro positivo (ex: "consecutive_failures >= 2").
 *
 * Sinais booleanos (átomos autónomos) vivem em {@see GuardSignals}.
 * A separação reflecte a distinção estrutural já existente no
 * {@see GuardParser} (existsSignal vs existsThreshold) e no
 * {@see CompiledGuard} (GuardNodeType::Signal vs GuardNodeType::Threshold).
 *
 * O {@see GuardParser} e o {@see GuardRegistry} trabalham com strings —
 * esta classe existe para eliminar literais espalhados pelo {@see \App\Services\V2\Agent\Kernel\TransitionMap}
 * e garantir que um typo é detectado pelo IDE ou pelo compilador estático,
 * não em runtime.
 *
 * Convenção de nomes: o identificador usa underscore (ex: "consecutive_failures").
 * A constante PHP usa underscore e maiúsculas (ex: CONSECUTIVE_FAILURES).
 *
 * @package App\Services\V2\Agent\Guard
 * @author  Eduardo Costa Nkuansambu
 */
final class GuardThresholds
{
    // ─── Falhas consecutivas ──────────────────────────────────────────────────

    public const CONSECUTIVE_FAILURES = 'consecutive_failures';

    private function __construct() {}
}
