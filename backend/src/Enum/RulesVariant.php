<?php

declare(strict_types=1);

namespace App\Enum;

enum RulesVariant: string
{
    case Official = 'official';
    case Compact = 'compact';
}
