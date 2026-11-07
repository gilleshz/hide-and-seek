<?php

declare(strict_types=1);

namespace App\Enum;

enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Severe = 'severe';

    public function rank(): int
    {
        return match ($this) {
            self::Severe => 3,
            self::Warning => 2,
            self::Info => 1,
        };
    }
}
