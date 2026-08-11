<?php

declare(strict_types=1);

namespace App\Enum;

enum TimeTrapStatus: string
{
    case Armed = 'armed';
    case Pending = 'pending';
    case Sprung = 'sprung';
}
