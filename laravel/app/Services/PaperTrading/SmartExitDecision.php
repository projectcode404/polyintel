<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

/**
 * SmartExitDecision
 *
 * Immutable value object returned by SmartExitEngineService::evaluate().
 * Contains the action to take and the reason why.
 */
final class SmartExitDecision
{
    const NO_ACTION         = 'NO_ACTION';
    const MOVE_TO_BREAKEVEN = 'MOVE_TO_BREAKEVEN';
    const PARTIAL_EXIT_50   = 'PARTIAL_EXIT_50';
    const FULL_EXIT         = 'FULL_EXIT';

    public function __construct(
        public readonly string $action,
        public readonly string $reason,
    ) {}

    public static function noAction(): self
    {
        return new self(self::NO_ACTION, 'No exit condition met');
    }

    public static function moveToBreakeven(string $reason): self
    {
        return new self(self::MOVE_TO_BREAKEVEN, $reason);
    }

    public static function partialExit(string $reason): self
    {
        return new self(self::PARTIAL_EXIT_50, $reason);
    }

    public static function fullExit(string $reason): self
    {
        return new self(self::FULL_EXIT, $reason);
    }

    public function isNoAction(): bool
    {
        return $this->action === self::NO_ACTION;
    }
}
