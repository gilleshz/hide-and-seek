<?php

declare(strict_types=1);

namespace App\Enum;

enum QuestionStatus: string
{
    case Open = 'open';
    case Vetoed = 'vetoed';
    case Randomized = 'randomized';
}
