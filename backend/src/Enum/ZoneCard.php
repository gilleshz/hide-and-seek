<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The three physical cards that change hiding-zone geo state. Factors are printed on the
 * cards, so the app applies them exactly.
 */
enum ZoneCard: string
{
    case ProsperousHome = 'prosperous_home';
    case TinyHome = 'tiny_home';
    case Move = 'move';

    public function radiusFactor(): ?float
    {
        return match ($this) {
            self::ProsperousHome => 1.5,
            self::TinyHome => 0.5,
            self::Move => null,
        };
    }

    // Move says so on the card; Tiny Home shrinking the zone mid-endgame would undo a capture in progress.
    public function playableDuringEndgame(): bool
    {
        return $this === self::ProsperousHome;
    }
}
