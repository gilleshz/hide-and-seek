<?php

declare(strict_types=1);

namespace App\Enum;

enum MeasuringResult: string
{
    case Closer = 'closer';
    case Further = 'further';
}
