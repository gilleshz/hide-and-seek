<?php

declare(strict_types=1);

namespace App;

use LongitudeOne\Spatial\PHP\Types\Geography\Point;

final class GeoDistance
{
    private const float EARTH_RADIUS_METERS = 6371008.8;

    public static function metersBetween(Point $a, Point $b): float
    {
        $lat1 = deg2rad($a->getLatitude());
        $lat2 = deg2rad($b->getLatitude());
        $deltaLat = $lat2 - $lat1;
        $deltaLng = deg2rad($b->getLongitude() - $a->getLongitude());

        $h = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($h)));
    }
}
