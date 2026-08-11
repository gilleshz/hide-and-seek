<?php

declare(strict_types=1);

namespace App\Enum;

enum RoundStatus: string
{
    case Lobby = 'lobby';
    case Hiding = 'hiding';
    case Seeking = 'seeking';
    case Ended = 'ended';
}
