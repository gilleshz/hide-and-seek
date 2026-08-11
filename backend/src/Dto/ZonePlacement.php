<?php

declare(strict_types=1);

namespace App\Dto;

use LongitudeOne\Spatial\PHP\Types\Geography\Point;

/**
 * Where a hiding team is settling: the station point, the radius, and the station name.
 * The name comes from the client: its transit overlay is the same LOOM output the server
 * imported, so the hider names the station they tapped instead of the server guessing.
 */
final readonly class ZonePlacement
{
    public function __construct(
        public Point $point,
        public ?float $radiusMeters = null,
        public ?string $stationName = null,
    ) {
    }
}
