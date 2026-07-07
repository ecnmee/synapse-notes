<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Guard;

/**
 * Tipos possíveis de nó na AST de uma expressão de guarda.
 *
 * Substituiu o campo $type: string no {@see CompiledGuard} para garantir
 * que apenas valores válidos sejam construídos. O {@see GuardEvaluator}
 * usa este enum nos seus blocos match(), obtendo exaustividade
 * verificada em compile-time pelo analisador estático (PHPStan/Psalm).
 *
 * @package App\Services\V2\Agent\Guard
 * @author  Eduardo Costa Nkuansambu
 */
enum GuardNodeType: string
{
    case Signal    = 'signal';
    case Threshold = 'threshold';
    case Not       = 'not';
    case And       = 'and';
    case Or        = 'or';
}
