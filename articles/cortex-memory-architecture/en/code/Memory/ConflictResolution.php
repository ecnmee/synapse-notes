<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Memory;

/**
 * Result of a semantic conflict resolution.
 *
 * Produced by {@see SemanticConflictResolver::resolve()}.
 * Consumed by {@see SemanticValidator} to decide the next step.
 *
 * --- Cases ---
 *
 * NO_CONFLICT     No active fact exists for the entity, normal promotion.
 * ALREADY_ACTIVE  An active fact exists with the same claim, nothing to do.
 * SUPERSEDED      Conflict resolved, the old fact was marked as superseded
 *                 and the new fact was already created as active. Do not
 *                 promote again.
 * REJECTED        Candidate rejected, lower confidence than the active
 *                 fact. Do not promote.
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
