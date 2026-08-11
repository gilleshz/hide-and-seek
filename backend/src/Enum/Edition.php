<?php

declare(strict_types=1);

namespace App\Enum;

enum Edition: string
{
    case Metric = 'metric';
    case Imperial = 'imperial';
}
