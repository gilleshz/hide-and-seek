<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\OverpassEmptyPolicy;
use App\ErrorKey;
use App\Exception\FunctionalException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Completes a shape-less GTFS feed from OSM route relations at ingest.
 *
 * Matching is coordinate-based on purpose: OSM node members carry no tags under
 * "out geom", and OSM and GTFS stop names differ in casing and spacing.
 */
final readonly class GtfsShapeCompletionService
{
    private const int MAX_ROUTES_WITHOUT_SHAPES = 500;
    private const int MAX_TRIPS_WITHOUT_SHAPES = 500_000;
    private const int MAX_RETAINED_STOP_TIMES = 5_000_000;
    private const int MAX_CANDIDATES = 2000;
    private const int GEOMETRY_CHUNK = 10;
    private const int COMPLETION_DEADLINE_SECONDS = 900;
    private const int DISCOVERY_TIMEOUT = 90;
    private const int GEOMETRY_TIMEOUT = 90;
    private const int GEOMETRY_MAX_BYTES = 8 * 1024 * 1024;
    private const int MAX_MATCH_POINTS = 2000;
    private const float MAX_MEAN_METERS = 250.0;
    private const float MAX_MAX_METERS = 600.0;
    private const float GAP_METERS = 150.0;
    private const float DUPLICATE_ENDPOINT_METERS = 400.0;
    private const float SAME_POINT_METERS = 0.01;
    private const float METERS_PER_DEGREE_LAT = 111_132.236;
    private const float METERS_PER_DEGREE_LON = 111_319.490_793_273_58;
    private const float EARTH_RADIUS_METERS = 6_371_008.8;

    /** @var array<int, string> */
    private const array ROUTE_TYPE_MAP = [
        0 => 'tram',
        1 => 'subway',
        2 => 'train',
        3 => 'bus',
        4 => 'ferry',
        5 => 'cable_tram',
        7 => 'funicular',
        11 => 'trolleybus',
        12 => 'monorail',
    ];

    /** @var list<string> */
    private const array DEFAULT_SHAPE_COLUMNS = [
        'shape_id',
        'shape_pt_lat',
        'shape_pt_lon',
        'shape_pt_sequence',
        'shape_dist_traveled',
    ];

    /** @var list<string> */
    private const array REQUIRED_SHAPE_COLUMNS = ['shape_id', 'shape_pt_lat', 'shape_pt_lon', 'shape_pt_sequence'];

    /** @var list<string> */
    private const array SKIPPED_ROLE_PREFIXES = ['platform', 'stop'];

    public function __construct(
        private OverpassHttpClient $overpass,
        private LoggerInterface $logger,
        #[Autowire('%app.transit_max_response_bytes%')]
        private int $maxResponseBytes,
    ) {
    }

    /**
     * "completed" means the feed has usable shapes afterwards, including feeds that needed no completion.
     *
     * @return array{completed: bool, shapes: int, routes: int, unmatched: list<string>}
     */
    public function completeIfNeeded(string $zipPath): array
    {
        @set_time_limit(1200);
        $started = microtime(true);
        $deadline = $started + self::COMPLETION_DEADLINE_SECONDS;

        $zip = $this->openArchive($zipPath);
        try {
            $feed = $this->readFeed($zip);
        } finally {
            $zip->close();
        }

        $derived = $this->derive($feed, $deadline);
        $result = $this->finish(
            $zipPath,
            $derived,
            $feed['shapeIds'],
            $feed['needed'] !== [],
            $feed['shaped'],
        );

        $this->logger->info(
            'GTFS shape completion: {routes} routes matched, {shapes} shapes, {trips} trips assigned'
                . ' from {candidates} candidates in {seconds}s.',
            [
                'routes' => count($derived['routeIds']),
                'shapes' => count($derived['relationIds']),
                'trips' => count($derived['tripShapes']),
                'candidates' => $derived['candidates'],
                'seconds' => round(microtime(true) - $started, 1),
            ],
        );
        if ($result['unmatched'] !== []) {
            $this->logger->warning('GTFS routes without a matching OSM shape: {refs}', [
                'refs' => array_slice($result['unmatched'], 0, 20),
            ]);
        }

        return $result;
    }

    private function openArchive(string $zipPath): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new FunctionalException(
                message: 'Invalid GTFS archive: failed to open ZIP file.',
                errorKey: 'gtfs.invalid_archive',
            );
        }

        return $zip;
    }

    /**
     * @return array{
     *     needed: array<string, array{routeId: string, shapeId: string}>,
     *     routes: array<string, array{ref: string, routeType: int}>,
     *     shapeIds: array<string, true>,
     *     shaped: bool,
     *     stops: array<string, array{lat: float, lon: float}>,
     *     patterns: list<array{routeId: string, ref: string, stops: list<string>, trips: list<string>}>,
     *     bbox: array{south: float, west: float, north: float, east: float}|null
     * }
     */
    private function readFeed(\ZipArchive $zip): array
    {
        $routes = $this->readRoutes($zip);
        $shapeIds = $this->readShapeIds($zip);
        $trips = $this->readNeededTrips($zip, $shapeIds);
        $needed = $trips['needed'];

        if ($needed === []) {
            return [
                'needed' => [],
                'routes' => $routes,
                'shapeIds' => $shapeIds,
                'shaped' => $trips['shaped'],
                'stops' => [],
                'patterns' => [],
                'bbox' => null,
            ];
        }

        $this->assertRouteCap($needed);

        $stops = $this->readStops($zip);
        $patterns = $this->readPatterns($zip, $needed, $routes, $stops);
        $bbox = $this->patternBbox($patterns, $stops);
        if ($bbox === null) {
            throw new FunctionalException(
                message: 'This GTFS feed has no route shapes, which the map needs.',
                errorKey: ErrorKey::GTFS_SHAPES_MISSING,
            );
        }

        return [
            'needed' => $needed,
            'routes' => $routes,
            'shapeIds' => $shapeIds,
            'shaped' => $trips['shaped'],
            'stops' => $stops,
            'patterns' => $patterns,
            'bbox' => $bbox,
        ];
    }

    /**
     * @param array<string, array{routeId: string, shapeId: string}> $needed
     */
    private function assertRouteCap(array $needed): void
    {
        $routeIds = [];
        foreach ($needed as $trip) {
            $routeIds[$trip['routeId']] = true;
        }
        if (count($routeIds) > self::MAX_ROUTES_WITHOUT_SHAPES) {
            $this->logger->warning('GTFS feed has {count} routes without shapes; completion is capped at {max}.', [
                'count' => count($routeIds),
                'max' => self::MAX_ROUTES_WITHOUT_SHAPES,
            ]);

            throw new FunctionalException(
                message: 'This GTFS feed has too many routes without route shapes to complete automatically.',
                errorKey: ErrorKey::GTFS_SHAPES_MISSING,
            );
        }
    }

    /**
     * @return array<string, array{ref: string, routeType: int}>
     */
    private function readRoutes(\ZipArchive $zip): array
    {
        $stream = $this->openEntry($zip, 'routes.txt');
        try {
            $map = $this->headerMap($stream, 'routes.txt');
            $routeIdIndex = $map['route_id'] ?? null;
            $typeIndex = $map['route_type'] ?? null;
            if ($routeIdIndex === null || $typeIndex === null) {
                throw new FunctionalException(
                    message: 'Invalid GTFS archive: routes.txt is missing required columns.',
                    errorKey: 'gtfs.invalid_archive',
                );
            }
            $shortIndex = $map['route_short_name'] ?? null;
            $longIndex = $map['route_long_name'] ?? null;

            $routes = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $routeId = trim((string) ($row[$routeIdIndex] ?? ''));
                if ($routeId === '') {
                    continue;
                }
                $short = $shortIndex !== null ? trim((string) ($row[$shortIndex] ?? '')) : '';
                $long = $longIndex !== null ? trim((string) ($row[$longIndex] ?? '')) : '';
                $rawType = $row[$typeIndex] ?? null;
                $routes[$routeId] = [
                    'ref' => $this->normalizeRef($short !== '' ? $short : $long),
                    'routeType' => is_numeric($rawType) ? (int) $rawType : -1,
                ];
            }

            return $routes;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param array<string, true> $shapeIds
     *
     * @return array{
     *     needed: array<string, array{routeId: string, shapeId: string}>,
     *     shaped: bool
     * }
     */
    private function readNeededTrips(\ZipArchive $zip, array $shapeIds): array
    {
        $stream = $this->openEntry($zip, 'trips.txt');
        try {
            $map = $this->headerMap($stream, 'trips.txt');
            $tripIndex = $map['trip_id'] ?? null;
            $routeIndex = $map['route_id'] ?? null;
            if ($tripIndex === null || $routeIndex === null) {
                throw new FunctionalException(
                    message: 'Invalid GTFS archive: trips.txt is missing required columns.',
                    errorKey: 'gtfs.invalid_archive',
                );
            }
            $shapeIndex = $map['shape_id'] ?? null;

            $needed = [];
            $shaped = false;
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $tripId = trim((string) ($row[$tripIndex] ?? ''));
                if ($tripId === '') {
                    continue;
                }
                $shapeId = $shapeIndex !== null ? trim((string) ($row[$shapeIndex] ?? '')) : '';
                if ($shapeId !== '' && isset($shapeIds[$shapeId])) {
                    $shaped = true;
                    continue;
                }
                $needed[$tripId] = [
                    'routeId' => trim((string) ($row[$routeIndex] ?? '')),
                    'shapeId' => $shapeId,
                ];
                if (count($needed) > self::MAX_TRIPS_WITHOUT_SHAPES) {
                    throw new FunctionalException(
                        message: 'This GTFS feed has too many trips without route shapes to complete automatically.',
                        errorKey: ErrorKey::GTFS_SHAPES_MISSING,
                    );
                }
            }

            return ['needed' => $needed, 'shaped' => $shaped];
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array<string, true>
     */
    private function readShapeIds(\ZipArchive $zip): array
    {
        if ($zip->locateName('shapes.txt') === false) {
            return [];
        }

        $stream = $this->openEntry($zip, 'shapes.txt');
        try {
            $map = $this->headerMap($stream, 'shapes.txt');
            $shapeIndex = $map['shape_id'] ?? null;
            if ($shapeIndex === null) {
                throw new FunctionalException(
                    message: 'Invalid GTFS archive: shapes.txt is missing the shape_id column.',
                    errorKey: 'gtfs.invalid_archive',
                );
            }

            $ids = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $shapeId = trim((string) ($row[$shapeIndex] ?? ''));
                if ($shapeId !== '') {
                    $ids[$shapeId] = true;
                }
            }

            return $ids;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array<string, array{lat: float, lon: float}>
     */
    private function readStops(\ZipArchive $zip): array
    {
        $stream = $this->openEntry($zip, 'stops.txt');
        try {
            $map = $this->headerMap($stream, 'stops.txt');
            $stopIndex = $map['stop_id'] ?? null;
            $latIndex = $map['stop_lat'] ?? null;
            $lonIndex = $map['stop_lon'] ?? null;
            if ($stopIndex === null || $latIndex === null || $lonIndex === null) {
                throw new FunctionalException(
                    message: 'Invalid GTFS archive: stops.txt is missing required columns.',
                    errorKey: 'gtfs.invalid_archive',
                );
            }

            $stops = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $stopId = trim((string) ($row[$stopIndex] ?? ''));
                $coordinate = $this->coordinate($row[$latIndex] ?? null, $row[$lonIndex] ?? null);
                // Null Island placeholders would stretch the Overpass bbox across continents.
                $nullIsland = $coordinate !== null && $coordinate['lat'] === 0.0 && $coordinate['lon'] === 0.0;
                if ($stopId === '' || $coordinate === null || $nullIsland) {
                    continue;
                }
                $stops[$stopId] = $coordinate;
            }

            return $stops;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param array<string, array{routeId: string, shapeId: string}> $needed
     * @param array<string, array{ref: string, routeType: int}> $routes
     * @param array<string, array{lat: float, lon: float}> $stops
     *
     * @return list<array{routeId: string, ref: string, stops: list<string>, trips: list<string>}>
     */
    private function readPatterns(\ZipArchive $zip, array $needed, array $routes, array $stops): array
    {
        $stream = $this->openEntry($zip, 'stop_times.txt');
        $context = ['needed' => $needed, 'routes' => $routes, 'stops' => $stops];
        $patterns = [];
        $byTrip = [];
        $retained = 0;

        try {
            $map = $this->headerMap($stream, 'stop_times.txt');
            $tripIndex = $map['trip_id'] ?? null;
            $stopIndex = $map['stop_id'] ?? null;
            $sequenceIndex = $map['stop_sequence'] ?? null;
            if ($tripIndex === null || $stopIndex === null || $sequenceIndex === null) {
                throw new FunctionalException(
                    message: 'Invalid GTFS archive: stop_times.txt is missing required columns.',
                    errorKey: 'gtfs.invalid_archive',
                );
            }

            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $tripId = trim((string) ($row[$tripIndex] ?? ''));
                $sequence = $row[$sequenceIndex] ?? null;
                if (!isset($needed[$tripId]) || !is_numeric($sequence)) {
                    continue;
                }
                ++$retained;
                if ($retained > self::MAX_RETAINED_STOP_TIMES) {
                    throw new FunctionalException(
                        message: 'This GTFS feed has too many stop times without route shapes'
                            . ' to complete automatically.',
                        errorKey: ErrorKey::GTFS_SHAPES_MISSING,
                    );
                }
                $byTrip[$tripId][(int) $sequence] = trim((string) ($row[$stopIndex] ?? ''));
            }
        } finally {
            fclose($stream);
        }

        foreach ($byTrip as $tripId => $stopsBySequence) {
            ksort($stopsBySequence);
            $this->addPattern($patterns, $tripId, array_values($stopsBySequence), $context);
        }

        return array_values($patterns);
    }

    /**
     * @param array<string, array{routeId: string, ref: string, stops: list<string>, trips: list<string>}> $patterns
     * @param list<string> $stopIds
     * @param array{
     *     needed: array<string, array{routeId: string, shapeId: string}>,
     *     routes: array<string, array{ref: string, routeType: int}>,
     *     stops: array<string, array{lat: float, lon: float}>
     * } $context
     */
    private function addPattern(array &$patterns, string $tripId, array $stopIds, array $context): void
    {
        if (!isset($context['needed'][$tripId])) {
            return;
        }

        $known = array_values(array_filter(
            $stopIds,
            static fn (string $stopId): bool => isset($context['stops'][$stopId]),
        ));
        if (count($known) < 2) {
            return;
        }

        $trip = $context['needed'][$tripId];
        $key = $trip['routeId'] . "\x1f" . implode("\x1f", $known);
        if (!isset($patterns[$key])) {
            $patterns[$key] = [
                'routeId' => $trip['routeId'],
                'ref' => $context['routes'][$trip['routeId']]['ref'] ?? '',
                'stops' => $known,
                'trips' => [],
            ];
        }
        $patterns[$key]['trips'][] = $tripId;
    }

    /**
     * @param list<array{routeId: string, ref: string, stops: list<string>, trips: list<string>}> $patterns
     * @param array<string, array{lat: float, lon: float}> $stops
     *
     * @return array{south: float, west: float, north: float, east: float}|null
     */
    private function patternBbox(array $patterns, array $stops): ?array
    {
        $referenced = [];
        foreach ($patterns as $pattern) {
            foreach ($pattern['stops'] as $stopId) {
                if (isset($stops[$stopId])) {
                    $referenced[$stopId] = $stops[$stopId];
                }
            }
        }

        return $this->bbox($referenced);
    }

    /**
     * @param array{
     *     needed: array<string, array{routeId: string, shapeId: string}>,
     *     routes: array<string, array{ref: string, routeType: int}>,
     *     shapeIds: array<string, true>,
     *     shaped: bool,
     *     stops: array<string, array{lat: float, lon: float}>,
     *     patterns: list<array{routeId: string, ref: string, stops: list<string>, trips: list<string>}>,
     *     bbox: array{south: float, west: float, north: float, east: float}|null
     * } $feed
     *
     * @return array{
     *     tripShapes: array<string, string>,
     *     relationIds: array<int, true>,
     *     routeIds: array<string, true>,
     *     unmatched: list<string>,
     *     shapes: array<int, array{ref: string, points: list<array{lat: float, lon: float}>}>,
     *     candidates: int
     * }
     */
    private function derive(array $feed, float $deadline): array
    {
        if ($feed['bbox'] === null) {
            return $this->emptyDerived();
        }

        $refs = $this->neededRefs($feed['needed'], $feed['routes']);
        if ($refs === []) {
            return $this->emptyDerived();
        }

        $this->assertWithinDeadline($deadline);
        $candidates = $this->discover($feed['bbox'], $refs, $this->neededRouteTypes($feed['needed'], $feed['routes']));
        $shapes = $this->fetchShapes($candidates, $deadline);

        return $this->match($feed['patterns'], $shapes, $feed['stops'], count($candidates));
    }

    private function assertWithinDeadline(float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw new FunctionalException(
                message: 'Completing this GTFS feed from OpenStreetMap took too long; retry the import.',
                errorKey: ErrorKey::GTFS_SHAPES_MISSING,
            );
        }
    }

    /**
     * @param array<string, array{routeId: string, shapeId: string}> $needed
     * @param array<string, array{ref: string, routeType: int}> $routes
     *
     * @return array<string, true>
     */
    private function neededRefs(array $needed, array $routes): array
    {
        $refs = [];
        foreach ($needed as $trip) {
            $ref = $routes[$trip['routeId']]['ref'] ?? '';
            if ($ref !== '') {
                $refs[$ref] = true;
            }
        }

        return $refs;
    }

    /**
     * @param array<string, array{routeId: string, shapeId: string}> $needed
     * @param array<string, array{ref: string, routeType: int}> $routes
     *
     * @return list<string>
     */
    private function neededRouteTypes(array $needed, array $routes): array
    {
        $types = [];
        foreach ($needed as $trip) {
            $routeType = $routes[$trip['routeId']]['routeType'] ?? -1;
            if (isset(self::ROUTE_TYPE_MAP[$routeType])) {
                $types[self::ROUTE_TYPE_MAP[$routeType]] = true;
            }
        }

        $values = array_keys($types);
        if ($values === []) {
            $values = array_values(array_unique(self::ROUTE_TYPE_MAP));
        }
        sort($values);

        return $values;
    }

    /**
     * @param array{south: float, west: float, north: float, east: float} $bbox
     * @param array<string, true> $refs
     * @param list<string> $types
     *
     * @return list<array{id: int, ref: string}>
     */
    private function discover(array $bbox, array $refs, array $types): array
    {
        $body = $this->fetchDiscovery($bbox, $types);
        $elements = $this->decodeJson($body)['elements'] ?? null;
        if (!is_array($elements)) {
            return [];
        }

        $allowed = array_fill_keys($types, true);
        $candidates = [];
        foreach ($elements as $element) {
            $candidate = $this->candidate($element, $refs, $allowed);
            if ($candidate === null) {
                continue;
            }
            $candidates[] = $candidate;
            if (count($candidates) > self::MAX_CANDIDATES) {
                $this->logger->warning('Overpass returned more than {max} matching route relations.', [
                    'max' => self::MAX_CANDIDATES,
                ]);

                throw new FunctionalException(
                    message: 'This GTFS feed has too many matching OSM routes to complete automatically.',
                    errorKey: ErrorKey::GTFS_SHAPES_MISSING,
                );
            }
        }

        return $candidates;
    }

    /**
     * @param array{south: float, west: float, north: float, east: float} $bbox
     * @param list<string> $types
     */
    private function fetchDiscovery(array $bbox, array $types): string
    {
        try {
            return $this->overpass->fetch(
                $this->buildDiscoveryQuery($this->bboxString($bbox), $types),
                self::DISCOVERY_TIMEOUT,
                $this->maxResponseBytes,
                OverpassEmptyPolicy::RejectAny,
            );
        } catch (\RuntimeException $e) {
            $this->logger->warning('Overpass discovery failed while completing GTFS shapes.', [
                'error' => $e->getMessage(),
            ]);

            throw new FunctionalException(
                message: 'This GTFS feed has no route shapes, which the map needs.',
                errorKey: ErrorKey::GTFS_SHAPES_MISSING,
                previous: $e,
            );
        }
    }

    /**
     * @param list<string> $types
     */
    private function buildDiscoveryQuery(string $bbox, array $types): string
    {
        $pattern = implode('|', array_map(
            static fn (string $type): string => preg_quote($type, '/'),
            $types,
        ));

        return "[out:json][timeout:" . self::DISCOVERY_TIMEOUT . '];'
            . 'relation["route"~"^(' . $pattern . ')$"](' . $bbox . ');'
            . 'out tags;';
    }

    /**
     * @param array{south: float, west: float, north: float, east: float} $bbox
     */
    private function bboxString(array $bbox): string
    {
        return number_format($bbox['south'], 7, '.', '')
            . ',' . number_format($bbox['west'], 7, '.', '')
            . ',' . number_format($bbox['north'], 7, '.', '')
            . ',' . number_format($bbox['east'], 7, '.', '');
    }

    /**
     * @param array<string, true> $refs
     * @param array<string, true> $allowed
     *
     * @return array{id: int, ref: string}|null
     */
    private function candidate(mixed $element, array $refs, array $allowed): ?array
    {
        $candidate = null;
        if (is_array($element) && ($element['type'] ?? null) === 'relation') {
            $id = $element['id'] ?? null;
            $tags = $element['tags'] ?? null;
            $route = is_array($tags) ? ($tags['route'] ?? null) : null;
            $ref = is_array($tags) ? ($tags['ref'] ?? null) : null;
            if (is_int($id) && $id > 0 && is_string($route) && isset($allowed[$route]) && is_string($ref)) {
                $normalized = $this->normalizeRef($ref);
                if ($normalized !== '' && isset($refs[$normalized])) {
                    $candidate = ['id' => $id, 'ref' => $normalized];
                }
            }
        }

        return $candidate;
    }

    /**
     * @param list<array{id: int, ref: string}> $candidates
     *
     * @return array<int, array{ref: string, points: list<array{lat: float, lon: float}>}>
     */
    private function fetchShapes(array $candidates, float $deadline): array
    {
        $refsById = [];
        foreach ($candidates as $candidate) {
            $refsById[$candidate['id']] = $candidate['ref'];
        }

        $shapes = [];
        foreach (array_chunk($refsById, self::GEOMETRY_CHUNK, true) as $chunk) {
            $this->assertWithinDeadline($deadline);
            foreach ($this->fetchGeometryChunk($chunk) as $relationId => $ways) {
                $points = $this->stitch($ways);
                if (count($points) >= 2) {
                    $shapes[$relationId] = ['ref' => $refsById[$relationId], 'points' => $points];
                }
            }
        }

        return $shapes;
    }

    /**
     * @param array<int, string> $refsById
     *
     * @return array<int, list<list<array{lat: float, lon: float}>>>
     */
    private function fetchGeometryChunk(array $refsById): array
    {
        try {
            $ways = $this->decodeGeometry($this->overpass->fetch(
                $this->buildGeometryQuery(array_keys($refsById)),
                self::GEOMETRY_TIMEOUT,
                min($this->maxResponseBytes, self::GEOMETRY_MAX_BYTES),
                OverpassEmptyPolicy::RejectIncompleteGeometry,
            ));
        } catch (\RuntimeException $e) {
            $this->logger->warning('Overpass geometry fetch failed.', ['error' => $e->getMessage()]);

            throw new FunctionalException(
                message: 'The OpenStreetMap geometry service is unavailable; retry the import.',
                errorKey: ErrorKey::GTFS_SHAPES_MISSING,
                previous: $e,
            );
        }
        if ($ways === []) {
            $this->logger->warning('Overpass returned no usable geometry for a relation chunk.');

            throw new FunctionalException(
                message: 'The OpenStreetMap geometry service returned no usable route geometry; retry the import.',
                errorKey: ErrorKey::GTFS_SHAPES_MISSING,
            );
        }

        return $ways;
    }

    /**
     * @param list<int> $relationIds
     */
    private function buildGeometryQuery(array $relationIds): string
    {
        return '[out:json][timeout:' . self::GEOMETRY_TIMEOUT . '];'
            . 'relation(id:' . implode(',', $relationIds) . ');'
            . 'out geom;';
    }

    /**
     * @return array<int, list<list<array{lat: float, lon: float}>>>
     */
    private function decodeGeometry(string $body): array
    {
        $elements = $this->decodeJson($body)['elements'] ?? null;
        if (!is_array($elements)) {
            return [];
        }

        $relations = [];
        foreach ($elements as $element) {
            $relation = $this->relationWays($element);
            if ($relation !== null) {
                $relations[$relation['id']] = $relation['ways'];
            }
        }

        return $relations;
    }

    /**
     * @return array{id: int, ways: list<list<array{lat: float, lon: float}>>}|null
     */
    private function relationWays(mixed $element): ?array
    {
        $relation = null;
        if (is_array($element) && ($element['type'] ?? null) === 'relation') {
            $id = $element['id'] ?? null;
            $members = $element['members'] ?? null;
            if (is_int($id) && $id > 0 && is_array($members)) {
                $ways = [];
                foreach ($members as $member) {
                    $way = $this->memberWays($member);
                    if ($way !== null) {
                        $ways[] = $way;
                    }
                }
                if ($ways !== []) {
                    $relation = ['id' => $id, 'ways' => $ways];
                }
            }
        }

        return $relation;
    }

    /**
     * @return list<array{lat: float, lon: float}>|null
     */
    private function memberWays(mixed $member): ?array
    {
        $way = null;
        if (is_array($member) && ($member['type'] ?? null) === 'way') {
            $role = $member['role'] ?? '';
            $geometry = $member['geometry'] ?? null;
            if (is_string($role) && !$this->isSkippedRole($role) && is_array($geometry)) {
                $points = [];
                $previous = null;
                foreach ($geometry as $raw) {
                    $point = $this->decodedPoint($raw);
                    if ($point === null) {
                        continue;
                    }
                    if ($previous !== null && $this->distanceMeters($previous, $point) <= self::SAME_POINT_METERS) {
                        continue;
                    }
                    $points[] = $point;
                    $previous = $point;
                }
                if (count($points) >= 2) {
                    $way = $points;
                }
            }
        }

        return $way;
    }

    private function isSkippedRole(string $role): bool
    {
        foreach (self::SKIPPED_ROLE_PREFIXES as $prefix) {
            if (str_starts_with($role, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    private function decodedPoint(mixed $raw): ?array
    {
        if (!is_array($raw) || !isset($raw['lat'], $raw['lon'])) {
            return null;
        }

        return $this->coordinate($raw['lat'], $raw['lon']);
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    private function coordinate(mixed $lat, mixed $lon): ?array
    {
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }
        $latitude = (float) $lat;
        $longitude = (float) $lon;
        if (!is_finite($latitude) || !is_finite($longitude)) {
            return null;
        }
        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            return null;
        }

        return ['lat' => $latitude, 'lon' => $longitude];
    }

    /**
     * @param list<list<array{lat: float, lon: float}>> $ways
     *
     * @return list<array{lat: float, lon: float}>
     */
    private function stitch(array $ways): array
    {
        $chains = [];
        $current = [];
        foreach ($ways as $way) {
            if ($current === []) {
                $current = $way;
                continue;
            }

            $oriented = $this->orient($current[count($current) - 1], $way);
            if ($oriented === null) {
                $chains[] = $current;
                $current = $way;
                continue;
            }

            $last = $current[count($current) - 1];
            foreach ($oriented as $point) {
                if ($this->distanceMeters($last, $point) > self::SAME_POINT_METERS) {
                    $current[] = $point;
                    $last = $point;
                }
            }
        }
        if ($current !== []) {
            $chains[] = $current;
        }

        return $this->longest($chains);
    }

    /**
     * @param array{lat: float, lon: float} $tail
     * @param list<array{lat: float, lon: float}> $way
     *
     * @return list<array{lat: float, lon: float}>|null
     */
    private function orient(array $tail, array $way): ?array
    {
        $toStart = $this->distanceMeters($tail, $way[0]);
        $toEnd = $this->distanceMeters($tail, $way[count($way) - 1]);
        $oriented = null;
        if (min($toStart, $toEnd) <= self::GAP_METERS) {
            $oriented = $toEnd < $toStart ? array_reverse($way) : $way;
        }

        return $oriented;
    }

    /**
     * @param list<list<array{lat: float, lon: float}>> $chains
     *
     * @return list<array{lat: float, lon: float}>
     */
    private function longest(array $chains): array
    {
        $best = [];
        $bestLength = -1.0;
        foreach ($chains as $chain) {
            $length = $this->lengthMeters($chain);
            if ($length > $bestLength) {
                $best = $chain;
                $bestLength = $length;
            }
        }

        return $best;
    }

    /**
     * @param list<array{lat: float, lon: float}> $points
     */
    private function lengthMeters(array $points): float
    {
        $length = 0.0;
        for ($i = 1, $count = count($points); $i < $count; ++$i) {
            $length += $this->distanceMeters($points[$i - 1], $points[$i]);
        }

        return $length;
    }

    /**
     * @param array{lat: float, lon: float} $a
     * @param array{lat: float, lon: float} $b
     */
    private function distanceMeters(array $a, array $b): float
    {
        $lat1 = deg2rad($a['lat']);
        $lat2 = deg2rad($b['lat']);
        $deltaLat = $lat2 - $lat1;
        $deltaLon = deg2rad($b['lon'] - $a['lon']);
        $h = sin($deltaLat / 2.0) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2.0) ** 2;

        return 2.0 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($h)));
    }

    /**
     * @param list<array{routeId: string, ref: string, stops: list<string>, trips: list<string>}> $patterns
     * @param array<int, array{ref: string, points: list<array{lat: float, lon: float}>}> $shapes
     * @param array<string, array{lat: float, lon: float}> $stops
     *
     * @return array{
     *     tripShapes: array<string, string>,
     *     relationIds: array<int, true>,
     *     routeIds: array<string, true>,
     *     unmatched: list<string>,
     *     shapes: array<int, array{ref: string, points: list<array{lat: float, lon: float}>}>,
     *     candidates: int
     * }
     */
    private function match(array $patterns, array $shapes, array $stops, int $candidateCount): array
    {
        $byRef = $this->candidatesByRef($shapes);
        $merged = [];
        $tripShapes = [];
        $relationIds = [];
        $routeIds = [];
        $assignedShapes = [];
        $unmatched = [];

        foreach ($patterns as $pattern) {
            $ref = $pattern['ref'];
            $candidates = $ref !== '' && isset($byRef[$ref]) ? $byRef[$ref] : [];
            $best = $this->bestCandidate($pattern['stops'], $candidates, $stops);
            if ($best === null && count($candidates) > 1) {
                $best = $this->bestMergedCandidate($pattern['stops'], $ref, $candidates, $stops, $merged);
            }
            if ($best === null) {
                if ($ref !== '') {
                    $unmatched[$ref] = true;
                }
                continue;
            }

            $shapeId = 'OSM_' . $best['relationId'];
            foreach ($pattern['trips'] as $tripId) {
                $tripShapes[$tripId] = $shapeId;
            }
            $relationIds[$best['relationId']] = true;
            $routeIds[$pattern['routeId']] = true;
            $assignedShapes[$best['relationId']] = ['ref' => $ref, 'points' => $best['geometry']];
        }

        $unmatchedRefs = array_keys($unmatched);
        sort($unmatchedRefs);

        return [
            'tripShapes' => $tripShapes,
            'relationIds' => $relationIds,
            'routeIds' => $routeIds,
            'unmatched' => $unmatchedRefs,
            'shapes' => $assignedShapes,
            'candidates' => $candidateCount,
        ];
    }

    /**
     * @param array<int, array{ref: string, points: list<array{lat: float, lon: float}>}> $shapes
     *
     * @return array<string, list<array{
     *     relationId: int,
     *     origin: array{lat: float, lon: float},
     *     points: list<array{float, float}>,
     *     geometry: list<array{lat: float, lon: float}>
     * }>>
     */
    private function candidatesByRef(array $shapes): array
    {
        $byRef = [];
        foreach ($shapes as $relationId => $shape) {
            $projected = $this->projectPoints($this->downsample($shape['points']));
            $byRef[$shape['ref']][] = [
                'relationId' => $relationId,
                'origin' => $projected['origin'],
                'points' => $projected['points'],
                'geometry' => $shape['points'],
            ];
        }

        return $byRef;
    }

    /**
     * @param list<array{lat: float, lon: float}> $points
     *
     * @return list<array{lat: float, lon: float}>
     */
    private function downsample(array $points): array
    {
        $count = count($points);
        if ($count <= self::MAX_MATCH_POINTS) {
            return $points;
        }

        $step = (int) ceil($count / self::MAX_MATCH_POINTS);
        $sampled = [];
        for ($i = 0; $i < $count; $i += $step) {
            $sampled[] = $points[$i];
        }
        $sampled[] = $points[$count - 1];

        return $sampled;
    }

    /**
     * @param list<array{lat: float, lon: float}> $points
     *
     * @return array{origin: array{lat: float, lon: float}, points: list<array{float, float}>}
     */
    private function projectPoints(array $points): array
    {
        $origin = $points[0];
        $scaleX = cos(deg2rad($origin['lat'])) * self::METERS_PER_DEGREE_LON;
        $projected = [];
        foreach ($points as $point) {
            $projected[] = [
                ($point['lon'] - $origin['lon']) * $scaleX,
                ($point['lat'] - $origin['lat']) * self::METERS_PER_DEGREE_LAT,
            ];
        }

        return ['origin' => $origin, 'points' => $projected];
    }

    /**
     * @param list<string> $stopIds
     * @param list<array{
     *     relationId: int,
     *     origin: array{lat: float, lon: float},
     *     points: list<array{float, float}>,
     *     geometry: list<array{lat: float, lon: float}>
     * }> $candidates
     * @param array<string, array{lat: float, lon: float}> $stops
     *
     * @return array{
     *     relationId: int,
     *     mean: float,
     *     max: float,
     *     geometry: list<array{lat: float, lon: float}>
     * }|null
     */
    private function bestCandidate(array $stopIds, array $candidates, array $stops): ?array
    {
        $best = null;
        foreach ($candidates as $candidate) {
            $stats = $this->matchStats($stopIds, $candidate, $stops);
            if ($stats['mean'] > self::MAX_MEAN_METERS || $stats['max'] > self::MAX_MAX_METERS) {
                continue;
            }
            $ranked = [
                'relationId' => $candidate['relationId'],
                'mean' => $stats['mean'],
                'max' => $stats['max'],
                'geometry' => $candidate['geometry'],
            ];
            if ($this->isBetter($ranked, $best)) {
                $best = $ranked;
            }
        }

        return $best;
    }

    /**
     * @param list<string> $stopIds
     * @param list<array{
     *     relationId: int,
     *     origin: array{lat: float, lon: float},
     *     points: list<array{float, float}>,
     *     geometry: list<array{lat: float, lon: float}>
     * }> $candidates
     * @param array<string, array{lat: float, lon: float}> $stops
     * @param array<string, array{
     *     relationId: int,
     *     origin: array{lat: float, lon: float},
     *     points: list<array{float, float}>,
     *     geometry: list<array{lat: float, lon: float}>
     * }|null> $merged
     *
     * @return array{
     *     relationId: int,
     *     mean: float,
     *     max: float,
     *     geometry: list<array{lat: float, lon: float}>
     * }|null
     */
    private function bestMergedCandidate(
        array $stopIds,
        string $ref,
        array $candidates,
        array $stops,
        array &$merged,
    ): ?array {
        $merged[$ref] ??= $this->buildMergedCandidate($candidates);
        $candidate = $merged[$ref];
        if ($candidate === null) {
            return null;
        }

        $stats = $this->matchStats($stopIds, $candidate, $stops);
        if ($stats['mean'] > self::MAX_MEAN_METERS || $stats['max'] > self::MAX_MAX_METERS) {
            return null;
        }

        return [
            'relationId' => $candidate['relationId'],
            'mean' => $stats['mean'],
            'max' => $stats['max'],
            'geometry' => $candidate['geometry'],
        ];
    }

    /**
     * @param list<array{
     *     relationId: int,
     *     origin: array{lat: float, lon: float},
     *     points: list<array{float, float}>,
     *     geometry: list<array{lat: float, lon: float}>
     * }> $candidates
     *
     * @return array{
     *     relationId: int,
     *     origin: array{lat: float, lon: float},
     *     points: list<array{float, float}>,
     *     geometry: list<array{lat: float, lon: float}>
     * }|null
     */
    private function buildMergedCandidate(array $candidates): ?array
    {
        $relationIds = [];
        foreach ($candidates as $candidate) {
            $relationIds[] = $candidate['relationId'];
        }
        $chains = $this->uniqueChains($candidates);
        if ($relationIds === [] || $chains === []) {
            return null;
        }

        $points = $this->joinChains($chains);
        if (count($points) < 2) {
            return null;
        }
        $projected = $this->projectPoints($this->downsample($points));

        return [
            'relationId' => min($relationIds),
            'origin' => $projected['origin'],
            'points' => $projected['points'],
            'geometry' => $points,
        ];
    }

    /**
     * @param list<array{
     *     relationId: int,
     *     origin: array{lat: float, lon: float},
     *     points: list<array{float, float}>,
     *     geometry: list<array{lat: float, lon: float}>
     * }> $candidates
     *
     * @return list<list<array{lat: float, lon: float}>>
     */
    private function uniqueChains(array $candidates): array
    {
        $kept = [];
        foreach ($candidates as $candidate) {
            $index = $this->duplicateChainIndex($candidate['geometry'], $kept);
            if ($index === null) {
                $kept[] = $candidate['geometry'];
            } elseif ($this->lengthMeters($candidate['geometry']) > $this->lengthMeters($kept[$index])) {
                $kept[$index] = $candidate['geometry'];
            }
        }

        return array_values($kept);
    }

    /**
     * @param list<array{lat: float, lon: float}> $points
     * @param array<int, list<array{lat: float, lon: float}>> $kept
     */
    private function duplicateChainIndex(array $points, array $kept): ?int
    {
        foreach ($kept as $index => $existing) {
            if ($this->sameChainEnds($points, $existing)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array{lat: float, lon: float}> $a
     * @param list<array{lat: float, lon: float}> $b
     */
    private function sameChainEnds(array $a, array $b): bool
    {
        $aStart = $a[0];
        $aEnd = $a[count($a) - 1];
        $bStart = $b[0];
        $bEnd = $b[count($b) - 1];
        $same = max($this->distanceMeters($aStart, $bStart), $this->distanceMeters($aEnd, $bEnd));
        $reverse = max($this->distanceMeters($aStart, $bEnd), $this->distanceMeters($aEnd, $bStart));

        return min($same, $reverse) <= self::DUPLICATE_ENDPOINT_METERS;
    }

    /**
     * @param non-empty-list<list<array{lat: float, lon: float}>> $chains
     *
     * @return list<array{lat: float, lon: float}>
     */
    private function joinChains(array $chains): array
    {
        $current = $chains[0];
        unset($chains[0]);
        $chains = array_values($chains);

        while ($chains !== []) {
            $bestIndex = 0;
            $bestReverse = false;
            $bestDistance = INF;
            $tail = $current[count($current) - 1];
            foreach ($chains as $index => $chain) {
                $toStart = $this->distanceMeters($tail, $chain[0]);
                $toEnd = $this->distanceMeters($tail, $chain[count($chain) - 1]);
                if ($toStart < $bestDistance) {
                    $bestIndex = $index;
                    $bestReverse = false;
                    $bestDistance = $toStart;
                }
                if ($toEnd < $bestDistance) {
                    $bestIndex = $index;
                    $bestReverse = true;
                    $bestDistance = $toEnd;
                }
            }
            $next = $bestReverse ? array_reverse($chains[$bestIndex]) : $chains[$bestIndex];
            $current = $this->appendChain($current, $next);
            unset($chains[$bestIndex]);
            $chains = array_values($chains);
        }

        return $current;
    }

    /**
     * @param list<array{lat: float, lon: float}> $current
     * @param list<array{lat: float, lon: float}> $next
     *
     * @return list<array{lat: float, lon: float}>
     */
    private function appendChain(array $current, array $next): array
    {
        $last = $current[count($current) - 1];
        foreach ($next as $point) {
            if ($this->distanceMeters($last, $point) > self::SAME_POINT_METERS) {
                $current[] = $point;
                $last = $point;
            }
        }

        return $current;
    }

    /**
     * @param list<string> $stopIds
     * @param array{
     *     relationId: int,
     *     origin: array{lat: float, lon: float},
     *     points: list<array{float, float}>
     * } $candidate
     * @param array<string, array{lat: float, lon: float}> $stops
     *
     * @return array{mean: float, max: float}
     */
    private function matchStats(array $stopIds, array $candidate, array $stops): array
    {
        $total = 0.0;
        $max = 0.0;
        foreach ($stopIds as $stopId) {
            $distance = $this->distanceToPolyline(
                $stops[$stopId]['lat'],
                $stops[$stopId]['lon'],
                $candidate,
            );
            $total += $distance;
            $max = max($max, $distance);
        }

        return ['mean' => $total / count($stopIds), 'max' => $max];
    }

    /**
     * @param array{
     *     relationId: int,
     *     origin: array{lat: float, lon: float},
     *     points: list<array{float, float}>
     * } $candidate
     */
    private function distanceToPolyline(float $lat, float $lon, array $candidate): float
    {
        $origin = $candidate['origin'];
        $scaleX = cos(deg2rad($origin['lat'])) * self::METERS_PER_DEGREE_LON;
        $px = ($lon - $origin['lon']) * $scaleX;
        $py = ($lat - $origin['lat']) * self::METERS_PER_DEGREE_LAT;

        $points = $candidate['points'];
        $best = INF;
        for ($i = 1, $count = count($points); $i < $count; ++$i) {
            $ax = $points[$i - 1][0];
            $ay = $points[$i - 1][1];
            $dx = $points[$i][0] - $ax;
            $dy = $points[$i][1] - $ay;
            $lengthSquared = $dx * $dx + $dy * $dy;
            $t = $lengthSquared > 0.0 ? (($px - $ax) * $dx + ($py - $ay) * $dy) / $lengthSquared : 0.0;
            $t = max(0.0, min(1.0, $t));
            $best = min($best, hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy)));
        }

        return $best;
    }

    /**
     * @param array{
     *     relationId: int,
     *     mean: float,
     *     max: float,
     *     geometry: list<array{lat: float, lon: float}>
     * } $candidate
     * @param array{
     *     relationId: int,
     *     mean: float,
     *     max: float,
     *     geometry: list<array{lat: float, lon: float}>
     * }|null $best
     */
    private function isBetter(array $candidate, ?array $best): bool
    {
        return match (true) {
            $best === null => true,
            $candidate['mean'] !== $best['mean'] => $candidate['mean'] < $best['mean'],
            $candidate['max'] !== $best['max'] => $candidate['max'] < $best['max'],
            default => $candidate['relationId'] < $best['relationId'],
        };
    }

    /**
     * @return array{
     *     tripShapes: array<string, string>,
     *     relationIds: array<int, true>,
     *     routeIds: array<string, true>,
     *     unmatched: list<string>,
     *     shapes: array<int, array{ref: string, points: list<array{lat: float, lon: float}>}>,
     *     candidates: int
     * }
     */
    private function emptyDerived(): array
    {
        return [
            'tripShapes' => [],
            'relationIds' => [],
            'routeIds' => [],
            'unmatched' => [],
            'shapes' => [],
            'candidates' => 0,
        ];
    }

    /**
     * @param array{
     *     tripShapes: array<string, string>,
     *     relationIds: array<int, true>,
     *     routeIds: array<string, true>,
     *     unmatched: list<string>,
     *     shapes: array<int, array{ref: string, points: list<array{lat: float, lon: float}>}>,
     *     candidates: int
     * } $derived
     * @param array<string, true> $existingShapeIds
     *
     * @return array{completed: bool, shapes: int, routes: int, unmatched: list<string>}
     */
    private function finish(
        string $zipPath,
        array $derived,
        array $existingShapeIds,
        bool $needed,
        bool $shaped,
    ): array {
        if ($derived['tripShapes'] === []) {
            return $this->result(!$needed || $shaped, 0, 0, $derived['unmatched']);
        }

        $this->writeCompleted($zipPath, $derived['tripShapes'], $derived['shapes'], $existingShapeIds);

        return $this->result(
            true,
            count($derived['relationIds']),
            count($derived['routeIds']),
            $derived['unmatched'],
        );
    }

    /**
     * @param list<string> $unmatched
     *
     * @return array{completed: bool, shapes: int, routes: int, unmatched: list<string>}
     */
    private function result(bool $completed, int $shapes, int $routes, array $unmatched): array
    {
        return ['completed' => $completed, 'shapes' => $shapes, 'routes' => $routes, 'unmatched' => $unmatched];
    }

    /**
     * @param array<string, string> $tripShapes
     * @param array<int, array{ref: string, points: list<array{lat: float, lon: float}>}> $shapes
     * @param array<string, true> $existingShapeIds
     */
    private function writeCompleted(string $zipPath, array $tripShapes, array $shapes, array $existingShapeIds): void
    {
        $suffix = bin2hex(random_bytes(8));
        $tmpZip = $zipPath . '.tmp-' . $suffix;
        $tripsPath = $zipPath . '.trips-' . $suffix . '.csv';
        $shapesPath = $zipPath . '.shapes-' . $suffix . '.csv';

        try {
            if (!copy($zipPath, $tmpZip)) {
                throw new \RuntimeException('Could not copy the GTFS archive for completion.');
            }
            $this->writeTrips($zipPath, $tripsPath, $tripShapes, $existingShapeIds);
            $this->writeShapes($zipPath, $shapesPath, $shapes);
            $this->replaceEntries($tmpZip, $tripsPath, $shapesPath);
            if (!rename($tmpZip, $zipPath)) {
                throw new \RuntimeException('Could not replace the GTFS archive.');
            }
        } finally {
            @unlink($tmpZip);
            @unlink($tripsPath);
            @unlink($shapesPath);
        }
    }

    /**
     * @param array<string, string> $tripShapes
     * @param array<string, true> $existingShapeIds
     */
    private function writeTrips(string $zipPath, string $outputPath, array $tripShapes, array $existingShapeIds): void
    {
        $zip = $this->openArchive($zipPath);
        $stream = $this->openEntry($zip, 'trips.txt');
        $out = fopen($outputPath, 'wb');
        if ($out === false) {
            fclose($stream);
            $zip->close();

            throw new \RuntimeException('Could not write the completed trips.txt.');
        }

        try {
            $header = $this->headerRow($stream, 'trips.txt');
            $map = $this->mapColumns($header);
            $tripIndex = $map['trip_id'] ?? null;
            if ($tripIndex === null) {
                throw new FunctionalException(
                    message: 'Invalid GTFS archive: trips.txt is missing the trip_id column.',
                    errorKey: 'gtfs.invalid_archive',
                );
            }

            $columns = array_map(static fn (string|null $column): string => (string) $column, $header);
            $shapeIndex = $map['shape_id'] ?? null;
            if ($shapeIndex === null) {
                $columns[] = 'shape_id';
                $shapeIndex = count($columns) - 1;
            }
            fputcsv($out, $columns, ',', '"', '');

            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $row = array_pad($row, count($columns), '');
                $tripId = trim((string) ($row[$tripIndex] ?? ''));
                $existing = trim((string) ($row[$shapeIndex] ?? ''));
                $assigned = $tripShapes[$tripId] ?? null;
                $row[$shapeIndex] = $assigned ?? (isset($existingShapeIds[$existing]) ? $existing : '');
                fputcsv($out, $row, ',', '"', '');
            }
        } finally {
            fclose($stream);
            fclose($out);
            $zip->close();
        }
    }

    /**
     * @param array<int, array{ref: string, points: list<array{lat: float, lon: float}>}> $shapes
     */
    private function writeShapes(string $zipPath, string $outputPath, array $shapes): void
    {
        $zip = $this->openArchive($zipPath);
        $out = fopen($outputPath, 'wb');
        if ($out === false) {
            $zip->close();

            throw new \RuntimeException('Could not write the completed shapes.txt.');
        }

        $stream = $zip->locateName('shapes.txt') !== false ? $zip->getStream('shapes.txt') : false;
        try {
            $columns = $this->existingShapeColumns($stream);
            fputcsv($out, $columns, ',', '"', '');
            if (is_resource($stream)) {
                while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                    fputcsv($out, $row, ',', '"', '');
                }
                fclose($stream);
                $stream = null;
            }

            ksort($shapes);
            foreach ($shapes as $relationId => $shape) {
                $this->appendShape($out, $columns, 'OSM_' . $relationId, $shape['points']);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            fclose($out);
            $zip->close();
        }
    }

    /**
     * @param resource|false|null $stream
     *
     * @return list<string>
     */
    private function existingShapeColumns(mixed $stream): array
    {
        $columns = self::DEFAULT_SHAPE_COLUMNS;
        if (is_resource($stream)) {
            $header = fgetcsv($stream, null, ',', '"', '');
            if ($header !== false && $this->validShapeHeader($header)) {
                $columns = array_map(static fn (string|null $column): string => (string) $column, $header);
            }
        }

        return $columns;
    }

    /**
     * @param array<int, string|null> $header
     */
    private function validShapeHeader(array $header): bool
    {
        $columns = array_map(static fn (string|null $column): string => (string) $column, $header);
        foreach (self::REQUIRED_SHAPE_COLUMNS as $required) {
            if (!in_array($required, $columns, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param resource $out
     * @param list<string> $columns
     * @param list<array{lat: float, lon: float}> $points
     */
    private function appendShape($out, array $columns, string $shapeId, array $points): void
    {
        $sequence = 0;
        $distance = 0.0;
        $previous = null;
        $previousLat = '';
        $previousLon = '';

        foreach ($points as $point) {
            $lat = number_format($point['lat'], 7, '.', '');
            $lon = number_format($point['lon'], 7, '.', '');
            if ($lat === $previousLat && $lon === $previousLon) {
                continue;
            }
            if ($previous !== null) {
                $distance += $this->distanceMeters($previous, $point);
            }
            ++$sequence;
            fputcsv($out, $this->shapeRow($columns, [
                'shape_id' => $shapeId,
                'shape_pt_lat' => $lat,
                'shape_pt_lon' => $lon,
                'shape_pt_sequence' => (string) $sequence,
                'shape_dist_traveled' => number_format($distance, 2, '.', ''),
            ]), ',', '"', '');
            $previous = $point;
            $previousLat = $lat;
            $previousLon = $lon;
        }
    }

    /**
     * @param list<string> $columns
     * @param array<string, string> $values
     *
     * @return list<string>
     */
    private function shapeRow(array $columns, array $values): array
    {
        $row = [];
        foreach ($columns as $column) {
            $row[] = $values[$column] ?? '';
        }

        return $row;
    }

    private function replaceEntries(string $tmpZip, string $tripsPath, string $shapesPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            throw new \RuntimeException('Could not open the copied GTFS archive.');
        }

        try {
            if ($zip->locateName('trips.txt') !== false) {
                $zip->deleteName('trips.txt');
            }
            if ($zip->locateName('shapes.txt') !== false) {
                $zip->deleteName('shapes.txt');
            }
            if (!$zip->addFile($tripsPath, 'trips.txt') || !$zip->addFile($shapesPath, 'shapes.txt')) {
                throw new \RuntimeException('Could not write the completed GTFS entries.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array<string, array{lat: float, lon: float}> $stops
     *
     * @return array{south: float, west: float, north: float, east: float}|null
     */
    private function bbox(array $stops): ?array
    {
        if ($stops === []) {
            return null;
        }

        $lats = [];
        $lons = [];
        foreach ($stops as $stop) {
            $lats[] = $stop['lat'];
            $lons[] = $stop['lon'];
        }

        return ['south' => min($lats), 'west' => min($lons), 'north' => max($lats), 'east' => max($lons)];
    }

    private function normalizeRef(string $ref): string
    {
        $collapsed = preg_replace('/[\s\p{Z}]+/u', '', trim($ref));

        return mb_strtoupper($collapsed ?? '');
    }

    /**
     * @return resource
     */
    private function openEntry(\ZipArchive $zip, string $entry)
    {
        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) {
            throw new FunctionalException(
                message: "The GTFS archive is missing {$entry}.",
                errorKey: 'gtfs.invalid_archive',
            );
        }

        return $stream;
    }

    /**
     * @param resource $stream
     *
     * @return array<string, int>
     */
    private function headerMap($stream, string $entry): array
    {
        return $this->mapColumns($this->headerRow($stream, $entry));
    }

    /**
     * @param array<int, string|null> $header
     *
     * @return array<string, int>
     */
    private function mapColumns(array $header): array
    {
        $map = [];
        foreach ($header as $index => $column) {
            $map[(string) $column] = $index;
        }

        return $map;
    }

    /**
     * @param resource $stream
     *
     * @return array<int, string|null>
     */
    private function headerRow($stream, string $entry): array
    {
        $header = fgetcsv($stream, null, ',', '"', '');
        if ($header === false) {
            throw new FunctionalException(
                message: "The GTFS archive has an empty {$entry}.",
                errorKey: 'gtfs.invalid_archive',
            );
        }

        return $header;
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(string $body): array
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FunctionalException(
                message: 'This GTFS feed has no route shapes, which the map needs.',
                errorKey: ErrorKey::GTFS_SHAPES_MISSING,
                previous: $e,
            );
        }
        if (!is_array($data)) {
            throw new FunctionalException(
                message: 'This GTFS feed has no route shapes, which the map needs.',
                errorKey: ErrorKey::GTFS_SHAPES_MISSING,
            );
        }

        return $data;
    }
}
