<?php

declare(strict_types=1);

namespace App\Enum;

enum GameSize: string
{
    case Small = 'S';
    case Medium = 'M';
    case Large = 'L';

    public function ordinal(): int
    {
        return match ($this) {
            self::Small => 0,
            self::Medium => 1,
            self::Large => 2,
        };
    }
}
