<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

/**
 * Resultado da resolução de conflito semântico.
 *
 * Produzido pelo {@see SemanticConflictResolver::resolve()}.
 * Consumido pelo {@see SemanticValidator} para decidir o próximo passo.
 *
 * ─── Casos ───────────────────────────────────────────────────────────────────
 *
 * NO_CONFLICT    Não existe facto activo para a entity — promoção normal.
 * ALREADY_ACTIVE Facto activo existe com o mesmo claim — nada a fazer.
 * SUPERSEDED     Conflito resolvido — facto antigo marcado como superseded,
 *                novo facto já criado como active. Não promover novamente.
 * REJECTED       Candidato rejeitado — confiança inferior ao facto activo.
 *                Não promover.
 *
 * @package App\Services\V2\Agent\Memory
 * @author  Eduardo Costa Nkuansambu
 */
final class ConflictResolution
{
    private function __construct(
        public readonly string $status,
    ) {}

    public static function noConflict(): self
    {
        return new self('no_conflict');
    }

    public static function alreadyActive(): self
    {
        return new self('already_active');
    }

    public static function superseded(): self
    {
        return new self('superseded');
    }

    public static function rejected(): self
    {
        return new self('rejected');
    }

    public function shouldProceedWithNormalPromotion(): bool
    {
        return $this->status === 'no_conflict';
    }

    public function shouldSkip(): bool
    {
        return in_array($this->status, ['already_active', 'superseded', 'rejected'], true);
    }
}
