<?php

declare(strict_types=1);

namespace App\Enum;

enum TimeTrapAction: string
{
    case Placed = 'placed';
    case Detected = 'detected';
    case Sprung = 'sprung';
    case Dismissed = 'dismissed';
}
