<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What an empty Overpass answer means to the caller. A mirror whose extract does not cover the queried
 * area answers with no elements, which is indistinguishable from a genuinely empty area.
 * RejectIncompleteGeometry also rejects relation member ways with no geometry: a regional extract that
 * stops before the route leaves its area answers with those members present but empty.
 */
enum OverpassEmptyPolicy
{
    case Allow;
    case RejectWithRemark;
    case RejectAny;
    case RejectIncompleteGeometry;
}
