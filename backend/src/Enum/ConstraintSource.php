<?php

declare(strict_types=1);

namespace App\Enum;

enum ConstraintSource: string
{
    case Proven = 'proven';
    case Manual = 'manual';
}
