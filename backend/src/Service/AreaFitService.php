<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AreaFitArea;
use App\Dto\AreaFitInput;
use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Repository\AdminAreaRepository;
use App\Repository\GtfsSourceRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns the lines the host picked into the communes they serve, so the play area can be derived
 * from the transit network instead of guessed from the area search.
 */
final readonly class AreaFitService
{
    /**
     * ponytail: level 8 is the commune/Gemeinde in FR/DE/BE/CH/AT/IT/ES/NL. A country whose
     * municipality sits at another level needs an explicit map, like AdminLevelResolver.
     */
    private const int MUNICIPALITY_LEVEL = 8;

    /**
     * ponytail: coarser fallbacks when a network serves more than 50 communes. Level 6 is the
     * department/Kreis/district, level 4 the region/Land/canton; a country whose municipality
     * uses another level may only get the coarser result.
     */
    private const array FALLBACK_LEVELS = [6, 4];

    /**
     * ponytail: the containment query sends every vertex or stop; 20k is plenty for a transit
     * network. Raise it, or switch to a spatial prefilter, if a large network ever misses communes.
     */
    private const int MAX_QUERY_POINTS = 20000;

    public function __construct(
        private TransitService $transit,
        private OverpassService $overpass,
        private GtfsSourceRepository $gtfsSources,
        private GtfsSubsetService $gtfsSubset,
        private AdminAreaRepository $adminAreas,
        private NominatimService $nominatim,
        #[Autowire('%app.max_boundary_span_deg%')]
        private float $maxBoundarySpanDeg,
    ) {
    }

    /** @return list<AreaFitArea> */
    public function fit(AreaFitInput $input): array
    {
        if ($input->osmLineIds === [] && $input->gtfsLines === []) {
            throw new FunctionalException(
                message: 'Select at least one transit line.',
                errorKey: ErrorKey::AREA_FIT_NO_LINES,
            );
        }

        $points = $this->dedupe(array_merge(
            $this->osmLinePoints($input->osmLineIds),
            $this->gtfsStopPoints($input->gtfsLines),
        ));
        if ($points === []) {
            throw new FunctionalException(
                message: 'The selected lines have no geometry to fit an area from.',
                errorKey: ErrorKey::AREA_FIT_NO_GEOMETRY,
            );
        }

        [$south, $north, $west, $east] = $this->bounds($points);
        if (max($north - $south, $east - $west) > $this->maxBoundarySpanDeg) {
            throw new FunctionalException(
                message: 'These lines cover too large an area to fit a play area.',
                errorKey: ErrorKey::AREA_FIT_TOO_LARGE,
            );
        }

        $bbox = sprintf('%.6F,%.6F,%.6F,%.6F', $south, $west, $north, $east);
        $queryPoints = $this->thin($points);

        $primary = $this->areasAtLevel(self::MUNICIPALITY_LEVEL, $bbox, $queryPoints);
        if ($this->fitsAreaLimit($primary)) {
            return $this->sorted($primary);
        }

        foreach (self::FALLBACK_LEVELS as $level) {
            $areas = $this->areasAtLevel($level, $bbox, $queryPoints);
            if ($this->fitsAreaLimit($areas)) {
                return $this->sorted($areas);
            }
        }

        if ($primary === []) {
            throw new FunctionalException(
                message: 'No communes were found around the selected lines.',
                errorKey: ErrorKey::AREA_FIT_NO_AREAS,
            );
        }

        throw new FunctionalException(
            message: sprintf(
                'These lines serve %d communes, over the %d area limit.',
                count($primary),
                NominatimService::MAX_LOOKUP_IDS,
            ),
            errorKey: ErrorKey::AREA_FIT_TOO_MANY_AREAS,
            errorArgs: ['count' => count($primary), 'max' => NominatimService::MAX_LOOKUP_IDS],
        );
    }

    /** @param list<AreaFitArea> $areas */
    private function fitsAreaLimit(array $areas): bool
    {
        return count($areas) >= 1 && count($areas) <= NominatimService::MAX_LOOKUP_IDS;
    }

    /** @param list<AreaFitArea> $areas
     *  @return list<AreaFitArea> */
    private function sorted(array $areas): array
    {
        usort($areas, static fn (AreaFitArea $a, AreaFitArea $b): int => strcasecmp($a->displayName, $b->displayName));

        return $areas;
    }

    /**
     * @param list<array{lat: float, lon: float}> $points
     * @return list<AreaFitArea>
     */
    private function areasAtLevel(int $level, string $bbox, array $points): array
    {
        $elements = $this->overpass->fetchAdminRelations($level, $bbox);
        $ways = $this->relationWays($elements, $level);
        $ids = $this->adminAreas->findContainingRelationIds($ways, $points);
        $ids = array_values(array_unique([
            ...$ids,
            ...$this->containingFromNominatim($this->adminAreas->findEmptyOuterRelationIds($ways), $points),
        ]));

        return $this->toAreas($elements, $ids, $level);
    }

    /**
     * @param list<int> $relationIds
     * @param list<array{lat: float, lon: float}> $points
     * @return list<int>
     */
    private function containingFromNominatim(array $relationIds, array $points): array
    {
        if ($relationIds === []) {
            return [];
        }

        $refs = array_map(
            static fn (int $id): array => ['osmType' => 'relation', 'osmId' => $id],
            array_slice($relationIds, 0, NominatimService::MAX_LOOKUP_IDS),
        );

        try {
            $geometries = $this->nominatim->fetchAreaGeometry($refs);
        } catch (\RuntimeException) {
            return [];
        }

        $polygons = array_map(
            static fn (array $geometry): array => ['relId' => $geometry['osmId'], 'geoJson' => $geometry['geoJson']],
            $geometries,
        );

        return $this->adminAreas->findContainingGeoJsonRelationIds($polygons, $points);
    }

    /**
     * @param list<string> $osmIds
     * @return list<array{lat: float, lon: float}>
     */
    private function osmLinePoints(array $osmIds): array
    {
        if ($osmIds === []) {
            return [];
        }

        $geoJson = $this->transit->previewGeometry($osmIds);
        if ($geoJson === null) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($geoJson, true, 512, JSON_THROW_ON_ERROR);
        $features = is_array($decoded) ? ($decoded['features'] ?? null) : null;
        if (!is_array($features)) {
            return [];
        }

        $points = [];
        foreach ($features as $feature) {
            $geometry = is_array($feature) ? ($feature['geometry'] ?? null) : null;
            $coordinates = is_array($geometry) ? ($geometry['coordinates'] ?? null) : null;
            if (!is_array($coordinates)) {
                continue;
            }
            foreach ($coordinates as $line) {
                if (!is_array($line)) {
                    continue;
                }
                foreach ($line as $coordinate) {
                    $point = self::geoJsonPoint($coordinate);
                    if ($point !== null) {
                        $points[] = $point;
                    }
                }
            }
        }

        return $points;
    }

    /**
     * @param list<array{gtfsSourceUuid: string, routeId: string}> $lines
     * @return list<array{lat: float, lon: float}>
     */
    private function gtfsStopPoints(array $lines): array
    {
        /** @var array<string, list<string>> $routeIdsBySource */
        $routeIdsBySource = [];
        foreach ($lines as $line) {
            $uuid = $line['gtfsSourceUuid'];
            $routeId = $line['routeId'];
            if ($uuid === '' || $routeId === '') {
                continue;
            }
            $routeIdsBySource[$uuid][] = $routeId;
        }

        $points = [];
        foreach ($routeIdsBySource as $uuid => $routeIds) {
            $source = $this->gtfsSources->findByUuid($uuid);
            if ($source === null) {
                continue;
            }
            // Route-filtered subset: the full feed would include stops of unselected routes.
            $path = $this->gtfsSubset->build($source, $routeIds);
            foreach ($this->gtfsSubset->stopCoordinates($path) as $point) {
                $points[] = $point;
            }
        }

        return $points;
    }

    /**
     * @param list<mixed> $elements
     * @return list<array{relId: int, role: string, points: list<array{lat: float, lon: float}>}>
     */
    private function relationWays(array $elements, int $level): array
    {
        $ways = [];
        foreach ($elements as $element) {
            if (!is_array($element) || ($element['type'] ?? '') !== 'relation' || !$this->isLevel($element, $level)) {
                continue;
            }
            $id = $element['id'] ?? null;
            if (!is_int($id) && !is_numeric($id)) {
                continue;
            }
            $members = $element['members'] ?? null;
            if (!is_array($members)) {
                continue;
            }
            foreach ($members as $member) {
                $way = $this->memberWay($member, (int) $id);
                if ($way !== null) {
                    $ways[] = $way;
                }
            }
        }

        return $ways;
    }

    private function isLevel(mixed $element, int $level): bool
    {
        $tags = is_array($element) ? ($element['tags'] ?? null) : null;
        if (!is_array($tags)) {
            return false;
        }
        $adminLevel = $tags['admin_level'] ?? null;

        return ($tags['boundary'] ?? null) === 'administrative'
            && is_numeric($adminLevel)
            && (int) $adminLevel === $level;
    }

    /**
     * @return array{relId: int, role: string, points: list<array{lat: float, lon: float}>}|null
     */
    private function memberWay(mixed $member, int $relId): ?array
    {
        if (!is_array($member) || ($member['type'] ?? '') !== 'way') {
            return null;
        }
        $geometry = $member['geometry'] ?? null;
        if (!is_array($geometry)) {
            return null;
        }

        $points = [];
        foreach ($geometry as $coordinate) {
            $point = self::latLonPoint($coordinate);
            if ($point !== null) {
                $points[] = $point;
            }
        }
        if (count($points) < 2) {
            return null;
        }

        $role = $member['role'] ?? null;

        return [
            'relId' => $relId,
            'role' => is_string($role) ? $role : '',
            'points' => $points,
        ];
    }

    /**
     * @param list<mixed> $elements
     * @param list<int> $ids
     * @return list<AreaFitArea>
     */
    private function toAreas(array $elements, array $ids, int $level): array
    {
        $wanted = array_fill_keys($ids, true);
        $areas = [];
        foreach ($elements as $element) {
            $id = is_array($element) ? ($element['id'] ?? null) : null;
            if (!is_int($id) && !is_numeric($id)) {
                continue;
            }
            $id = (int) $id;
            if (!isset($wanted[$id])) {
                continue;
            }
            unset($wanted[$id]);

            $tags = $element['tags'] ?? null;
            $areas[] = new AreaFitArea(
                osmType: 'relation',
                osmId: (string) $id,
                displayName: self::areaName(is_array($tags) ? $tags : [], $id),
                adminLevel: $level,
            );
        }

        return $areas;
    }

    /**
     * @param array<mixed> $tags
     */
    private static function areaName(array $tags, int $id): string
    {
        foreach (['name', 'name:fr', 'name:en'] as $key) {
            $name = $tags[$key] ?? null;
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return 'OSM relation ' . $id;
    }

    /**
     * @param list<array{lat: float, lon: float}> $points
     * @return list<array{lat: float, lon: float}>
     */
    private function dedupe(array $points): array
    {
        $seen = [];
        $unique = [];
        foreach ($points as $point) {
            $key = sprintf('%.5F,%.5F', $point['lat'], $point['lon']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $point;
        }

        return $unique;
    }

    /**
     * @param list<array{lat: float, lon: float}> $points
     * @return list<array{lat: float, lon: float}>
     */
    private function thin(array $points): array
    {
        $count = count($points);
        if ($count <= self::MAX_QUERY_POINTS) {
            return $points;
        }

        $step = $count / self::MAX_QUERY_POINTS;
        $sampled = [];
        for ($i = 0; $i < $count; $i += $step) {
            $sampled[] = $points[(int) $i];
        }

        return $sampled;
    }

    /**
     * @param list<array{lat: float, lon: float}> $points
     * @return array{float, float, float, float} south, north, west, east
     */
    private function bounds(array $points): array
    {
        $south = 90.0;
        $north = -90.0;
        $west = 180.0;
        $east = -180.0;
        foreach ($points as $point) {
            $south = min($south, $point['lat']);
            $north = max($north, $point['lat']);
            $west = min($west, $point['lon']);
            $east = max($east, $point['lon']);
        }

        return [$south, $north, $west, $east];
    }

    /** @return array{lat: float, lon: float}|null */
    private static function geoJsonPoint(mixed $coordinate): ?array
    {
        if (!is_array($coordinate) || !isset($coordinate[0], $coordinate[1])) {
            return null;
        }
        if (!is_numeric($coordinate[0]) || !is_numeric($coordinate[1])) {
            return null;
        }

        return ['lat' => (float) $coordinate[1], 'lon' => (float) $coordinate[0]];
    }

    /** @return array{lat: float, lon: float}|null */
    private static function latLonPoint(mixed $coordinate): ?array
    {
        $lat = is_array($coordinate) ? ($coordinate['lat'] ?? null) : null;
        $lon = is_array($coordinate) ? ($coordinate['lon'] ?? null) : null;
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        return ['lat' => (float) $lat, 'lon' => (float) $lon];
    }
}
