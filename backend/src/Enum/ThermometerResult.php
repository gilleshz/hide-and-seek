<?php

declare(strict_types=1);

namespace App\Enum;

enum ThermometerResult: string
{
    case Hotter = 'hotter';
    case Colder = 'colder';
}
