<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Time-bonus cards a hider still held when caught. The app does not model the deck, so
 * hiders total their own cards and the server only does the arithmetic.
 */
final readonly class ScoreBonus
{
    public function __construct(
        public int $minutes = 0,
        public int $percent = 0,
    ) {
    }

    public function isNone(): bool
    {
        return $this->minutes === 0 && $this->percent === 0;
    }

    /**
     * Percent bonuses apply to the raw hiding time, never to other bonuses, so they add up
     * instead of compounding.
     */
    public function secondsFor(int $hidingSeconds): int
    {
        return $this->minutes * 60 + intdiv(max(0, $hidingSeconds) * $this->percent, 100);
    }
}
