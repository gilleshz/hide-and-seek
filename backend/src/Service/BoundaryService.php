<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\GameAreaRepository;

class BoundaryService
{
    public function __construct(
        private NominatimService $nominatim,
        private GameAreaRepository $gameAreaRepository,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $areas
     */
    public function previewBoundary(array $areas): string
    {
        $geometries = $this->fetchGeometries($areas);

        return $this->gameAreaRepository->unionGeoJsonStrings($geometries);
    }

    /**
     * @param list<array<string, mixed>> $areas
     * @return string|null "swLat,swLng,neLat,neLng" or null if unresolvable
     */
    public function computeBbox(array $areas): ?string
    {
        $geometries = $this->fetchGeometries($areas);
        if ($geometries === []) {
            return null;
        }

        $swLat = 90.0;
        $swLng = 180.0;
        $neLat = -90.0;
        $neLng = -180.0;

        foreach ($geometries as $geoJson) {
            $decoded = json_decode($geoJson, true, 512, JSON_THROW_ON_ERROR);
            if (
                !is_array($decoded)
                || !isset($decoded['type'], $decoded['coordinates'])
                || !is_array($decoded['coordinates'])
            ) {
                continue;
            }
            /** @var array{type: string, coordinates: mixed} $decoded */
            $this->expandBounds($decoded, $swLat, $swLng, $neLat, $neLng);
        }

        if ($swLat > $neLat) {
            return null;
        }

        return "{$swLat},{$swLng},{$neLat},{$neLng}";
    }

    /** @param list<array<string, mixed>> $areas
     *  @return list<string> GeoJSON strings */
    private function fetchGeometries(array $areas): array
    {
        if ($areas === []) {
            return [];
        }

        $refs = array_map(static fn(array $a): array => [
            'osmType' => isset($a['osmType']) && is_string($a['osmType']) ? $a['osmType'] : 'relation',
            'osmId' => isset($a['osmId']) && is_numeric($a['osmId']) ? (int) $a['osmId'] : 0,
        ], $areas);

        $results = $this->nominatim->fetchAreaGeometry($refs);

        return array_map(static fn(array $r): string => $r['geoJson'], $results);
    }

    /** @param array{type: string, coordinates: mixed} $geometry */
    private function expandBounds(array $geometry, float &$swLat, float &$swLng, float &$neLat, float &$neLng): void
    {
        $type = $geometry['type'];
        $coords = $geometry['coordinates'];

        if (!is_array($coords)) {
            return;
        }

        if ($type === 'Polygon') {
            $this->expandRing($coords[0] ?? [], $swLat, $swLng, $neLat, $neLng);
        } elseif ($type === 'MultiPolygon') {
            foreach ($coords as $polygon) {
                if (is_array($polygon)) {
                    $this->expandRing($polygon[0] ?? [], $swLat, $swLng, $neLat, $neLng);
                }
            }
        }
    }

    /** @param mixed $ring */
    private function expandRing($ring, float &$swLat, float &$swLng, float &$neLat, float &$neLng): void
    {
        if (!is_array($ring)) {
            return;
        }
        foreach ($ring as $point) {
            if (!is_array($point) || !isset($point[0], $point[1]) || !is_numeric($point[0]) || !is_numeric($point[1])) {
                continue;
            }
            $lng = (float) $point[0];
            $lat = (float) $point[1];
            if ($lat < $swLat) {
                $swLat = $lat;
            }
            if ($lng < $swLng) {
                $swLng = $lng;
            }
            if ($lat > $neLat) {
                $neLat = $lat;
            }
            if ($lng > $neLng) {
                $neLng = $lng;
            }
        }
    }
}
