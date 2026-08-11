<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\OverpassEmptyPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TransitService
{
    private const string ROUTE_REGEX = '^(subway|light_rail|tram|train|monorail|funicular|trolleybus|bus)$';

    /** Stop and platform members carry the same nodes as the track ways and would draw as clutter. */
    private const array SKIPPED_MEMBER_ROLE_PREFIXES = ['platform', 'stop'];

    /** ~1 m, well past what a 200 dp setup map can show, and it roughly halves the response. */
    private const int PREVIEW_COORDINATE_PRECISION = 5;

    private const array COLOUR_PALETTES = [
        'subway' => ['#1A6FB5', '#2566AD', '#1F5FA0', '#2979C0', '#1E6DBF', '#2370B8'],
        'light_rail' => ['#2E8B8B', '#269999', '#33A0A0', '#288888', '#30B0B0', '#2C9494'],
        'tram' => ['#2E8B57', '#26994D', '#33A55A', '#288040', '#30B860', '#2C9050'],
        'train' => ['#6B2E6B', '#7A2680', '#853385', '#602060', '#9040B8', '#6E2C90'],
        'monorail' => ['#B8860B', '#C4900D', '#A8780A', '#D09010', '#B07808', '#C8880C'],
        'funicular' => ['#CC5500', '#D06010', '#C05000', '#E06000', '#C85808', '#D45800'],
        'trolleybus' => ['#B2226B', '#A81E60', '#C02870', '#9E1C5A', '#C82068', '#B4246E'],
        'bus' => ['#B22222', '#A81E1E', '#C02828', '#9E1C1C', '#C82020', '#B42424'],
    ];

    private const array DEFAULT_COLOUR_PALETTE = ['#666666', '#777777', '#555555', '#888888', '#6A6A6A', '#707070'];

    public function __construct(
        private OverpassHttpClient $overpassHttpClient,
        #[Autowire('%app.transit_max_response_bytes%')]
        private int $maxResponseBytes,
        private BoundaryService $boundaryService,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $areas
     * @param string[]|null $routeTypes  When non-empty, restrict discovery to only these route types
     * @return list<array{
     *     osmId: string, osmType: string, ref: string, name: string, nameEn: string,
     *     colour: string, routeType: string, network: string, operator: string
     * }>
     */
    public function discoverLines(array $areas, ?array $routeTypes = null): array
    {
        $bbox = $this->boundaryService->computeBbox($areas);
        if ($bbox === null) {
            return [];
        }

        $lines = [];
        foreach ($this->fetchElements($this->buildDiscoveryQuery($bbox, $routeTypes)) as $element) {
            if (!is_array($element) || ($element['type'] ?? '') !== 'relation') {
                continue;
            }
            $tags = $element['tags'] ?? [];
            if (!is_array($tags)) {
                continue;
            }
            $routeType = $tags['route'] ?? '';
            if (!is_string($routeType) || $routeType === '' || !preg_match('/' . self::ROUTE_REGEX . '/', $routeType)) {
                continue;
            }
            $ref = is_string($tags['ref'] ?? null) ? $tags['ref'] : '';
            $name = is_string($tags['name'] ?? null) ? $tags['name'] : '';
            $nameEn = is_string($tags['name:en'] ?? null) ? $tags['name:en'] : '';
            $rawColour = $tags['colour'] ?? null;
            $colour = is_string($rawColour) ? $rawColour : '';
            $network = is_string($tags['network'] ?? null) ? $tags['network'] : '';
            $operator = is_string($tags['operator'] ?? null) ? $tags['operator'] : '';
            $rawId = $element['id'] ?? null;
            $osmIdInt = is_int($rawId) ? $rawId : (is_string($rawId) ? (int) $rawId : 0);

            if ($ref === '' && $name === '') {
                continue;
            }
            if ($ref === '' && $routeType !== 'bus') {
                continue;
            }

            if ($colour === '') {
                $colour = $this->fallbackColour($routeType, $ref, $osmIdInt);
            }

            $lines[] = [
                'osmId' => $osmIdInt > 0 ? (string) $osmIdInt : '',
                'osmType' => 'relation',
                'ref' => $ref,
                'name' => $name,
                'nameEn' => $nameEn,
                'colour' => $colour,
                'routeType' => $routeType,
                'network' => $network,
                'operator' => $operator,
            ];
        }

        return $lines;
    }

    /**
     * Geometry of the given route relations as a GeoJSON FeatureCollection, one MultiLineString per
     * line, for the setup-time map preview, not the game overlay (LOOM builds that at creation).
     *
     * @param list<string> $osmIds
     * @return string|null null when nothing in the selection has drawable geometry
     */
    public function previewGeometry(array $osmIds): ?string
    {
        $relationIds = $this->numericIds($osmIds);
        if ($relationIds === []) {
            return null;
        }

        $features = [];
        foreach ($this->fetchElements($this->buildPreviewQuery($relationIds)) as $element) {
            $feature = $this->lineFeature($element);
            if ($feature !== null) {
                $features[] = $feature;
            }
        }

        if ($features === []) {
            return null;
        }

        return json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<mixed>
     */
    private function fetchElements(string $query): array
    {
        try {
            $json = $this->overpassHttpClient->fetch(
                $query,
                180,
                $this->maxResponseBytes,
                OverpassEmptyPolicy::RejectWithRemark,
            );
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Failed to decode Overpass response: ' . $e->getMessage(), 0, $e);
        }

        $elements = is_array($data) ? ($data['elements'] ?? null) : null;

        return is_array($elements) ? array_values($elements) : [];
    }

    /**
     * @param list<string> $osmIds
     * @return list<int>
     */
    private function numericIds(array $osmIds): array
    {
        $ids = [];
        foreach ($osmIds as $osmId) {
            if (is_numeric($osmId) && (int) $osmId > 0) {
                $ids[] = (int) $osmId;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string, mixed>|null */
    private function lineFeature(mixed $element): ?array
    {
        if (!is_array($element) || ($element['type'] ?? '') !== 'relation') {
            return null;
        }

        $lines = $this->memberLines($element['members'] ?? null);
        if ($lines === []) {
            return null;
        }

        $tags = $element['tags'] ?? null;

        return [
            'type' => 'Feature',
            'geometry' => ['type' => 'MultiLineString', 'coordinates' => $lines],
            'properties' => $this->lineProperties(is_array($tags) ? $tags : [], $element['id'] ?? null),
        ];
    }

    /**
     * osmId is echoed so the client can cache geometry and ask only for what it is missing.
     *
     * @param array<array-key, mixed> $tags
     * @return array{osmId: string, ref: string, name: string, routeType: string, colour: string}
     */
    private function lineProperties(array $tags, mixed $id): array
    {
        $routeType = self::tagString($tags, 'route');
        $ref = self::tagString($tags, 'ref');
        $colour = self::tagString($tags, 'colour');
        $osmId = is_int($id) ? $id : (is_string($id) ? (int) $id : 0);

        return [
            'osmId' => (string) $osmId,
            'ref' => $ref,
            'name' => self::tagString($tags, 'name'),
            'routeType' => $routeType,
            'colour' => $colour !== '' ? $colour : $this->fallbackColour($routeType, $ref, $osmId),
        ];
    }

    /** @param array<array-key, mixed> $tags */
    private static function tagString(array $tags, string $key): string
    {
        $value = $tags[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @return list<list<array{float, float}>> */
    private function memberLines(mixed $members): array
    {
        if (!is_array($members)) {
            return [];
        }

        $lines = [];
        foreach ($members as $member) {
            $line = $this->memberLine($member);
            if (count($line) >= 2) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @return list<array{float, float}> */
    private function memberLine(mixed $member): array
    {
        if (!is_array($member) || ($member['type'] ?? '') !== 'way' || $this->isSkippedRole($member['role'] ?? null)) {
            return [];
        }

        $geometry = $member['geometry'] ?? null;
        if (!is_array($geometry)) {
            return [];
        }

        $points = [];
        $previous = null;
        foreach ($geometry as $point) {
            $coordinate = $this->coordinate($point);
            if ($coordinate === null || $coordinate === $previous) {
                continue;
            }
            $points[] = $coordinate;
            $previous = $coordinate;
        }

        return $points;
    }

    private function isSkippedRole(mixed $role): bool
    {
        if (!is_string($role) || $role === '') {
            return false;
        }

        foreach (self::SKIPPED_MEMBER_ROLE_PREFIXES as $prefix) {
            if (str_starts_with($role, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{float, float}|null */
    private function coordinate(mixed $point): ?array
    {
        if (!is_array($point) || !isset($point['lat'], $point['lon'])) {
            return null;
        }
        if (!is_numeric($point['lat']) || !is_numeric($point['lon'])) {
            return null;
        }

        return [
            round((float) $point['lon'], self::PREVIEW_COORDINATE_PRECISION),
            round((float) $point['lat'], self::PREVIEW_COORDINATE_PRECISION),
        ];
    }

    /** @param list<int> $relationIds */
    private function buildPreviewQuery(array $relationIds): string
    {
        $ids = implode(',', $relationIds);

        return <<<QL
            [out:json][timeout:90];
            relation(id:{$ids});
            out geom;
        QL;
    }

    /**
     * @param string[]|null $routeTypes
     */
    private function buildDiscoveryQuery(string $bbox, ?array $routeTypes = null): string
    {
        if ($routeTypes === null) {
            $pattern = str_replace('$', '\$', self::ROUTE_REGEX);
        } elseif ($routeTypes === []) {
            // Explicitly empty: the client asked for zero route types, so match nothing.
            $pattern = '^$';
        } else {
            $escaped = array_map(static fn (string $t): string => preg_quote($t, '/'), $routeTypes);
            $pattern = '^(' . implode('|', $escaped) . ')$';
        }

        return <<<QL
            [out:json][timeout:90];
            (
              relation["route"~"{$pattern}"]({$bbox});
            );
            out tags;
        QL;
    }

    private function fallbackColour(string $routeType, string $ref, int $osmId): string
    {
        $palette = self::COLOUR_PALETTES[$routeType] ?? self::DEFAULT_COLOUR_PALETTE;
        $seed = $ref !== '' ? $ref : (string) $osmId;
        $index = abs(crc32($seed)) % count($palette);

        return $palette[$index];
    }
}
