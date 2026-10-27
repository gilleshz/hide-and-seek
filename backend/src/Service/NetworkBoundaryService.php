<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Edition;
use App\Enum\GameSize;
use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Repository\BoundaryGeometryRepository;
use App\Repository\GtfsSourceRepository;
use App\RoundTiming;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Derives the play area from the selected transit lines: a convex hull of the network, buffered
 * for the largest hiding zone and opened to round its corners.
 */
final readonly class NetworkBoundaryService
{
    // Margins calibrated so the opening rounds the corners without clipping the 1,125 m zone disks.
    private const float ZONE_FACTOR = 2.25;
    private const float BASE_EXTRA_METERS = 200.0;
    private const float ROUND_EXTRA_METERS = 975.0;

    public function __construct(
        private TransitService $transit,
        private GtfsSourceRepository $gtfsSources,
        private GtfsSubsetService $gtfsSubset,
        private BoundaryGeometryRepository $boundaries,
        #[Autowire('%app.max_boundary_span_deg%')]
        private float $maxBoundarySpanDeg,
    ) {
    }

    /**
     * @param list<string> $osmLineIds
     * @param list<array<string, mixed>> $gtfsLines
     */
    public function preview(array $osmLineIds, array $gtfsLines, GameSize $size, Edition $edition): string
    {
        $geometry = $this->buildGeometry($osmLineIds, $gtfsLines, $size, $edition);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($geometry, true, 512, JSON_THROW_ON_ERROR);

        return json_encode([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => $decoded,
                'properties' => new \stdClass(),
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $osmLineIds
     * @param list<array<string, mixed>> $gtfsLines
     */
    public function buildGeometry(array $osmLineIds, array $gtfsLines, GameSize $size, Edition $edition): string
    {
        if ($size === GameSize::Large) {
            throw new FunctionalException(
                message: 'Line-derived boundaries are only available for Small and Medium games.',
                errorKey: ErrorKey::GAME_BOUNDARY_AREAS_REQUIRED,
            );
        }

        if ($osmLineIds === [] && $gtfsLines === []) {
            throw new FunctionalException(
                message: 'Select at least one transit line.',
                errorKey: ErrorKey::AREA_FIT_NO_LINES,
            );
        }

        $gtfs = $this->gtfsGeometry($gtfsLines);
        $lines = [...$this->osmLineStrings($osmLineIds), ...$gtfs['lines']];
        $points = $gtfs['points'];
        if ($lines === [] && $points === []) {
            throw new FunctionalException(
                message: 'The selected lines have no geometry to fit an area from.',
                errorKey: ErrorKey::AREA_FIT_NO_GEOMETRY,
            );
        }

        $this->assertSpan($lines, $points);

        $radius = RoundTiming::defaultRadiusMeters($size, $edition);
        $base = $radius * self::ZONE_FACTOR + self::BASE_EXTRA_METERS;
        $round = $base + self::ROUND_EXTRA_METERS;
        $geometryJson = $this->geometryJson($lines, $points);
        $boundary = $this->boundaries->roundedConvexHull($geometryJson, $base, $round);
        if (!self::hasCoordinates($boundary)) {
            // A line-shaped network is thinner than the rounding disc, so the opening erases it.
            $boundary = $this->boundaries->roundedConvexHull($geometryJson, $base, 0.0);
        }

        if ($boundary === null || !self::hasCoordinates($boundary)) {
            throw new FunctionalException(
                message: 'The selected lines have no geometry to fit an area from.',
                errorKey: ErrorKey::AREA_FIT_NO_GEOMETRY,
            );
        }

        $span = self::boundarySpan($boundary);
        if ($span !== null && $span > $this->maxBoundarySpanDeg) {
            throw new FunctionalException(
                message: 'These lines cover too large an area to fit a play area.',
                errorKey: ErrorKey::AREA_FIT_TOO_LARGE,
            );
        }

        return $boundary;
    }

    /**
     * @param list<string> $osmIds
     * @return list<list<array{0: float, 1: float}>> GeoJSON line strings, each a list of [lng, lat]
     */
    private function osmLineStrings(array $osmIds): array
    {
        if ($osmIds === []) {
            return [];
        }

        $geoJson = $this->transit->previewGeometry($osmIds);
        $decoded = $geoJson === null ? null : json_decode($geoJson, true, 512, JSON_THROW_ON_ERROR);
        $features = is_array($decoded) ? ($decoded['features'] ?? null) : null;

        $lines = [];
        foreach (is_array($features) ? $features : [] as $feature) {
            $geometry = is_array($feature) ? ($feature['geometry'] ?? null) : null;
            $coordinates = is_array($geometry) ? ($geometry['coordinates'] ?? null) : null;
            if (!is_array($coordinates)) {
                continue;
            }
            foreach ($coordinates as $line) {
                $points = [];
                foreach (is_array($line) ? $line : [] as $coordinate) {
                    $point = self::coordinate($coordinate);
                    if ($point !== null) {
                        $points[] = $point;
                    }
                }
                if (count($points) >= 2) {
                    $lines[] = $points;
                }
            }
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $gtfsLines
     * @return array{lines: list<list<array{0: float, 1: float}>>, points: list<array{0: float, 1: float}>}
     */
    private function gtfsGeometry(array $gtfsLines): array
    {
        /** @var array<string, list<string>> $routeIdsBySource */
        $routeIdsBySource = [];
        foreach ($gtfsLines as $line) {
            $uuid = $line['gtfsSourceUuid'] ?? null;
            $routeId = $line['routeId'] ?? null;
            if (is_string($uuid) && $uuid !== '' && is_string($routeId) && $routeId !== '') {
                $routeIdsBySource[$uuid][] = $routeId;
            }
        }

        $lines = [];
        $points = [];
        foreach ($routeIdsBySource as $uuid => $routeIds) {
            $source = $this->gtfsSources->findByUuid($uuid);
            if ($source === null) {
                continue;
            }
            $path = $this->gtfsSubset->build($source, $routeIds);
            foreach ($this->gtfsSubset->shapeCoordinates($path) as $shape) {
                if (count($shape) >= 2) {
                    $lines[] = array_map(self::toGeoJsonPoint(...), $shape);
                }
            }
            foreach ($this->gtfsSubset->stopCoordinates($path) as $stop) {
                $points[] = [$stop['lon'], $stop['lat']];
            }
        }

        return ['lines' => $lines, 'points' => $points];
    }

    /**
     * @param list<list<array{0: float, 1: float}>> $lines
     * @param list<array{0: float, 1: float}> $points
     */
    private function assertSpan(array $lines, array $points): void
    {
        $south = 90.0;
        $north = -90.0;
        $west = 180.0;
        $east = -180.0;

        foreach ([...$lines, $points] as $shape) {
            foreach ($shape as $point) {
                $south = min($south, $point[1]);
                $north = max($north, $point[1]);
                $west = min($west, $point[0]);
                $east = max($east, $point[0]);
            }
        }

        if (max($north - $south, $east - $west) > $this->maxBoundarySpanDeg) {
            throw new FunctionalException(
                message: 'These lines cover too large an area to fit a play area.',
                errorKey: ErrorKey::AREA_FIT_TOO_LARGE,
            );
        }
    }

    /**
     * @param list<list<array{0: float, 1: float}>> $lines
     * @param list<array{0: float, 1: float}> $points
     */
    private function geometryJson(array $lines, array $points): string
    {
        $geometries = [];
        if ($lines !== []) {
            $geometries[] = ['type' => 'MultiLineString', 'coordinates' => $lines];
        }
        if ($points !== []) {
            $geometries[] = ['type' => 'MultiPoint', 'coordinates' => $points];
        }

        $geometry = count($geometries) === 1
            ? $geometries[0]
            : ['type' => 'GeometryCollection', 'geometries' => $geometries];

        return json_encode($geometry, JSON_THROW_ON_ERROR);
    }

    private static function hasCoordinates(?string $geoJson): bool
    {
        if ($geoJson === null) {
            return false;
        }

        $decoded = json_decode($geoJson, true, 512, JSON_THROW_ON_ERROR);
        $coordinates = is_array($decoded) ? ($decoded['coordinates'] ?? null) : null;
        $geometries = is_array($decoded) ? ($decoded['geometries'] ?? null) : null;

        return is_array($coordinates)
            ? $coordinates !== []
            : is_array($geometries) && $geometries !== [];
    }

    private static function boundarySpan(string $geoJson): ?float
    {
        $decoded = json_decode($geoJson, true, 512, JSON_THROW_ON_ERROR);
        $coordinates = is_array($decoded) ? ($decoded['coordinates'] ?? null) : null;
        if (!is_array($coordinates)) {
            return null;
        }

        $polygons = ($decoded['type'] ?? null) === 'MultiPolygon' ? $coordinates : [$coordinates];
        $south = 90.0;
        $north = -90.0;
        $west = 180.0;
        $east = -180.0;
        foreach ($polygons as $polygon) {
            if (!is_array($polygon)) {
                continue;
            }
            foreach ($polygon as $ring) {
                if (!is_array($ring)) {
                    continue;
                }
                foreach ($ring as $point) {
                    if (!is_array($point) || !isset($point[0], $point[1])) {
                        continue;
                    }
                    if (!is_numeric($point[0]) || !is_numeric($point[1])) {
                        continue;
                    }
                    $lng = (float) $point[0];
                    $lat = (float) $point[1];
                    $south = min($south, $lat);
                    $north = max($north, $lat);
                    $west = min($west, $lng);
                    $east = max($east, $lng);
                }
            }
        }

        return $south > $north ? null : max($north - $south, $east - $west);
    }

    /** @param array{lat: float, lon: float} $point
     *  @return array{0: float, 1: float} */
    private static function toGeoJsonPoint(array $point): array
    {
        return [$point['lon'], $point['lat']];
    }

    /** @return array{0: float, 1: float}|null */
    private static function coordinate(mixed $coordinate): ?array
    {
        if (!is_array($coordinate) || !isset($coordinate[0], $coordinate[1])) {
            return null;
        }
        if (!is_numeric($coordinate[0]) || !is_numeric($coordinate[1])) {
            return null;
        }

        return [(float) $coordinate[0], (float) $coordinate[1]];
    }
}
