<?php

declare(strict_types=1);

namespace App;

use App\Enum\StreetClass;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;

final class StreetNetworkTrim
{
    private const float METERS_PER_DEGREE_LATITUDE = 111320.0;

    /** Keeps the longitude scale finite next to a pole, where the cosine collapses towards zero. */
    private const float MIN_LONGITUDE_SCALE = 0.01;

    public static function bbox(Point $center, float $radiusMeters): string
    {
        $bounds = self::bounds($center, $radiusMeters);

        return sprintf(
            '%.6f,%.6f,%.6f,%.6f',
            $bounds['south'],
            $bounds['west'],
            $bounds['north'],
            $bounds['east'],
        );
    }

    /**
     * @return list<array{
     *     class: string,
     *     coordinates: list<array{0: float, 1: float}>,
     *     junctionIndices: list<int>,
     * }>
     *
     * @throws \JsonException
     */
    public static function ways(string $overpassJson, Point $center, float $radiusMeters): array
    {
        $decoded = json_decode($overpassJson, true, 512, JSON_THROW_ON_ERROR);
        $elements = is_array($decoded) ? ($decoded['elements'] ?? null) : null;
        $bounds = self::bounds($center, $radiusMeters);

        $kept = [];
        foreach (is_array($elements) ? $elements : [] as $element) {
            foreach (self::clippedWays($element, $bounds) as $way) {
                $kept[] = $way;
            }
        }

        return self::withJunctions($kept);
    }

    /**
     * @return array{south: float, west: float, north: float, east: float}
     */
    private static function bounds(Point $center, float $radiusMeters): array
    {
        $latitude = $center->getLatitude();
        $longitude = $center->getLongitude();
        $reach = $radiusMeters + StreetNetworkRules::BBOX_MARGIN_METERS;

        $deltaLatitude = $reach / self::METERS_PER_DEGREE_LATITUDE;
        $scale = max(self::MIN_LONGITUDE_SCALE, cos(deg2rad($latitude)));
        $deltaLongitude = $reach / (self::METERS_PER_DEGREE_LATITUDE * $scale);

        return [
            'south' => $latitude - $deltaLatitude,
            'west' => $longitude - $deltaLongitude,
            'north' => $latitude + $deltaLatitude,
            'east' => $longitude + $deltaLongitude,
        ];
    }

    /**
     * Overpass `out geom` returns every way whole, so a way that merely grazes the bbox would otherwise
     * reach kilometres outside the zone and be traceable as an answer there.
     *
     * @param array{south: float, west: float, north: float, east: float} $bounds
     *
     * @return list<array{class: string, coordinates: list<array{0: float, 1: float}>}>
     */
    private static function clippedWays(mixed $element, array $bounds): array
    {
        if (!is_array($element) || ($element['type'] ?? null) !== 'way') {
            return [];
        }

        $geometry = $element['geometry'] ?? null;
        $tags = $element['tags'] ?? null;
        $class = StreetClass::fromTags(is_array($tags) ? $tags : [])->value;

        $ways = [];
        foreach (self::runsInside(is_array($geometry) ? self::coordinates($geometry) : [], $bounds) as $run) {
            $ways[] = ['class' => $class, 'coordinates' => $run];
        }

        return $ways;
    }

    /**
     * @param list<array{0: float, 1: float}>                             $coordinates
     * @param array{south: float, west: float, north: float, east: float} $bounds
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private static function runsInside(array $coordinates, array $bounds): array
    {
        $runs = [];
        $run = [];

        foreach ($coordinates as $coordinate) {
            if (self::inside($coordinate, $bounds)) {
                $run[] = $coordinate;

                continue;
            }
            if (count($run) >= 2) {
                $runs[] = $run;
            }
            $run = [];
        }

        if (count($run) >= 2) {
            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * @param array{0: float, 1: float}                                   $coordinate
     * @param array{south: float, west: float, north: float, east: float} $bounds
     */
    private static function inside(array $coordinate, array $bounds): bool
    {
        return $coordinate[0] >= $bounds['west'] && $coordinate[0] <= $bounds['east']
            && $coordinate[1] >= $bounds['south'] && $coordinate[1] <= $bounds['north'];
    }

    /**
     * @param array<mixed> $geometry
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function coordinates(array $geometry): array
    {
        $coordinates = [];
        $previous = null;

        foreach ($geometry as $node) {
            $pair = self::pair($node);
            if ($pair === null || $pair === $previous) {
                continue;
            }
            $coordinates[] = $pair;
            $previous = $pair;
        }

        return $coordinates;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private static function pair(mixed $node): ?array
    {
        $latitude = is_array($node) ? ($node['lat'] ?? null) : null;
        $longitude = is_array($node) ? ($node['lon'] ?? null) : null;
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        return [
            round((float) $longitude, StreetNetworkRules::COORDINATE_DECIMALS),
            round((float) $latitude, StreetNetworkRules::COORDINATE_DECIMALS),
        ];
    }

    /**
     * Junctions come from the rounded coordinates rather than Overpass node ids, so the trim holds whether
     * or not `out geom` returned them, and coincident nodes of two ways still meet at 5 decimals.
     *
     * @param list<array{class: string, coordinates: list<array{0: float, 1: float}>}> $kept
     *
     * @return list<array{
     *     class: string,
     *     coordinates: list<array{0: float, 1: float}>,
     *     junctionIndices: list<int>,
     * }>
     */
    private static function withJunctions(array $kept): array
    {
        $owners = [];
        foreach ($kept as $wayIndex => $way) {
            foreach ($way['coordinates'] as $coordinate) {
                $owners[self::key($coordinate)][$wayIndex] = true;
            }
        }

        $ways = [];
        foreach ($kept as $way) {
            $junctionIndices = [];
            foreach ($way['coordinates'] as $position => $coordinate) {
                if (count($owners[self::key($coordinate)] ?? []) > 1) {
                    $junctionIndices[] = $position;
                }
            }
            $ways[] = [
                'class' => $way['class'],
                'coordinates' => $way['coordinates'],
                'junctionIndices' => $junctionIndices,
            ];
        }

        return $ways;
    }

    /**
     * The `+ 0.0` collapses negative zero: without it a rounded -0.0 formats as "-0.00000" and a way meeting
     * another within a metre of the prime meridian or the equator would not share its key.
     *
     * @param array{0: float, 1: float} $coordinate
     */
    private static function key(array $coordinate): string
    {
        $decimals = StreetNetworkRules::COORDINATE_DECIMALS;

        return number_format($coordinate[0] + 0.0, $decimals, '.', '')
            . ',' . number_format($coordinate[1] + 0.0, $decimals, '.', '');
    }
}
