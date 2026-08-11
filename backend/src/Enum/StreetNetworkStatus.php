<?php

declare(strict_types=1);

namespace App\Enum;

enum StreetNetworkStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Unavailable = 'unavailable';
}
