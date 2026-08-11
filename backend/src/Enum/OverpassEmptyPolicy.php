<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What an empty Overpass answer means to the caller. A mirror whose extract does not cover the queried
 * area answers with no elements, which is indistinguishable from a genuinely empty area.
 */
enum OverpassEmptyPolicy
{
    case Allow;
    case RejectWithRemark;
    case RejectAny;
}
