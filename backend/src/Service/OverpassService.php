<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feature;
use App\Entity\Game;
use App\Enum\FeatureType;
use App\Repository\FeatureRepository;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;

readonly class OverpassService
{
    /**
     * Cap the buffered download: decoding JSON to arrays costs several times the byte size,
     * so a dense boundary could otherwise exhaust memory_limit and OOM into an HTML fatal.
     */
    private const int MAX_RESPONSE_BYTES = 100_663_296;

    /**
     * @var array<string, array<string, FeatureType>>
     */
    private const array TAG_MAP = [
        'aeroway' => ['aerodrome' => FeatureType::CommercialAirport],
        'amenity' => [
            'hospital' => FeatureType::Hospital,
            'library' => FeatureType::Library,
            'cinema' => FeatureType::MovieTheater,
            'embassy' => FeatureType::Consulate,
        ],
        'tourism' => [
            'museum' => FeatureType::Museum,
            'zoo' => FeatureType::Zoo,
            'aquarium' => FeatureType::Aquarium,
            'theme_park' => FeatureType::AmusementPark,
        ],
        'leisure' => [
            'park' => FeatureType::Park,
            'golf_course' => FeatureType::GolfCourse,
        ],
        'natural' => [
            'peak' => FeatureType::Mountain,
            'water' => FeatureType::BodyOfWater,
            'coastline' => FeatureType::Coastline,
        ],
        'railway' => ['station' => FeatureType::RailStation],
        'highspeed' => ['yes' => FeatureType::HighSpeedRailLine],
        'public_transport' => ['station' => FeatureType::TransitStation],
        'office' => [
            'diplomatic' => FeatureType::Consulate,
        ],
        'waterway' => [
            'river' => FeatureType::BodyOfWater,
            'canal' => FeatureType::BodyOfWater,
        ],
    ];

    /**
     * @var array<string, list<array{string, string, string, extraTags?: array<string, string>}>>
     */
    private const array TYPE_DEFINITIONS = [
        FeatureType::Hospital->value => [
            ['node', 'amenity', 'hospital'],
            ['way', 'amenity', 'hospital'],
            ['relation', 'amenity', 'hospital'],
        ],
        FeatureType::Library->value => [
            ['node', 'amenity', 'library'],
            ['way', 'amenity', 'library'],
        ],
        FeatureType::Museum->value => [
            ['node', 'tourism', 'museum'],
            ['way', 'tourism', 'museum'],
        ],
        FeatureType::MovieTheater->value => [
            ['node', 'amenity', 'cinema'],
            ['way', 'amenity', 'cinema'],
        ],
        FeatureType::Zoo->value => [
            ['node', 'tourism', 'zoo'],
            ['way', 'tourism', 'zoo'],
        ],
        FeatureType::Aquarium->value => [
            ['node', 'tourism', 'aquarium'],
            ['way', 'tourism', 'aquarium'],
        ],
        FeatureType::AmusementPark->value => [
            ['node', 'tourism', 'theme_park'],
            ['way', 'tourism', 'theme_park'],
        ],
        FeatureType::GolfCourse->value => [
            ['node', 'leisure', 'golf_course'],
            ['way', 'leisure', 'golf_course'],
            ['relation', 'leisure', 'golf_course'],
        ],
        FeatureType::Consulate->value => [
            ['node', 'amenity', 'embassy'],
            ['node', 'office', 'diplomatic'],
            ['way', 'office', 'diplomatic'],
        ],
        FeatureType::Mountain->value => [['node', 'natural', 'peak']],
        FeatureType::CommercialAirport->value => [
            ['node', 'aeroway', 'aerodrome', ['iata' => '']],
            ['way', 'aeroway', 'aerodrome', ['iata' => '']],
            ['relation', 'aeroway', 'aerodrome', ['iata' => '']],
        ],
        FeatureType::RailStation->value => [['node', 'railway', 'station']],
        FeatureType::HighSpeedRailLine->value => [
            ['way', 'highspeed', 'yes'],
            ['relation', 'highspeed', 'yes'],
        ],
        FeatureType::TransitStation->value => [['node', 'public_transport', 'station']],
        FeatureType::Park->value => [
            ['node', 'leisure', 'park'],
            ['way', 'leisure', 'park'],
            ['relation', 'leisure', 'park'],
        ],
        FeatureType::BodyOfWater->value => [
            ['node', 'natural', 'water'],
            ['way', 'natural', 'water'],
            ['relation', 'natural', 'water'],
            ['way', 'waterway', 'river'],
            ['way', 'waterway', 'canal'],
        ],
        FeatureType::Coastline->value => [
            ['node', 'natural', 'coastline'],
            ['way', 'natural', 'coastline'],
        ],
    ];

    public function __construct(
        private OverpassHttpClient $overpassHttpClient,
        private FeatureRepository $features,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function ingestFeatures(Game $game): int
    {
        $swLat = $game->getBoundarySwLat();
        $swLng = $game->getBoundarySwLng();
        $neLat = $game->getBoundaryNeLat();
        $neLng = $game->getBoundaryNeLng();

        if ($swLat === null || $swLng === null || $neLat === null || $neLng === null) {
            throw new \RuntimeException('Game has no boundary set. Set boundary before ingesting features.');
        }

        $bbox = "{$swLat},{$swLng},{$neLat},{$neLng}";
        $query = $this->buildQuery($bbox);

        return $this->fetchAndProcess($query, $game);
    }

    public function ingestFeatureType(Game $game, FeatureType $type): int
    {
        $swLat = $game->getBoundarySwLat();
        $swLng = $game->getBoundarySwLng();
        $neLat = $game->getBoundaryNeLat();
        $neLng = $game->getBoundaryNeLng();

        if ($swLat === null || $swLng === null || $neLat === null || $neLng === null) {
            throw new \RuntimeException('Game has no boundary set. Set boundary before ingesting features.');
        }

        if ($type->isAdminBorder()) {
            return $this->ingestAdminBorder($game, $type);
        }

        if ($type->adminRank() !== null) {
            return $this->ingestAdminDivision($game, $type);
        }

        if (!isset(self::TYPE_DEFINITIONS[$type->value])) {
            throw new \RuntimeException(
                sprintf('Feature type "%s" has no Overpass query mapping.', $type->value),
            );
        }

        $bbox = "{$swLat},{$swLng},{$neLat},{$neLng}";
        $query = $this->buildQueryForTypes([$type], $bbox);

        return $this->ingestQueryLocked($game, $type, $query);
    }

    private function ingestAdminDivision(Game $game, FeatureType $type): int
    {
        $rank = $type->adminRank();
        $level = $rank === null ? null : ($game->getAdminLevels()[$rank] ?? null);
        if ($level === null) {
            return 0;
        }

        $swLat = $game->getBoundarySwLat();
        $swLng = $game->getBoundarySwLng();
        $neLat = $game->getBoundaryNeLat();
        $neLng = $game->getBoundaryNeLng();
        $bbox = "{$swLat},{$swLng},{$neLat},{$neLng}";
        $query = $this->buildAdminQuery($level, $bbox);

        return $this->ingestQueryLocked($game, $type, $query, $type);
    }

    private function ingestAdminBorder(Game $game, FeatureType $type): int
    {
        $level = $this->borderLevel($game, $type);
        if ($level === null) {
            return 0;
        }

        $swLat = $game->getBoundarySwLat();
        $swLng = $game->getBoundarySwLng();
        $neLat = $game->getBoundaryNeLat();
        $neLng = $game->getBoundaryNeLng();
        $bbox = "{$swLat},{$swLng},{$neLat},{$neLng}";
        $query = $this->buildBorderQuery($level, $bbox);

        return $this->ingestQueryLocked($game, $type, $query, $type);
    }

    private function borderLevel(Game $game, FeatureType $type): ?int
    {
        return match ($type) {
            FeatureType::BorderInternational => 2,
            FeatureType::Border1st => $game->getAdminLevels()[1] ?? null,
            FeatureType::Border2nd => $game->getAdminLevels()[2] ?? null,
            default => null,
        };
    }

    /**
     * Double-checked locking: only the first caller fetches from Overpass;
     * waiters find the features already persisted.
     */
    private function ingestQueryLocked(
        Game $game,
        FeatureType $type,
        string $query,
        ?FeatureType $forceType = null,
    ): int {
        if ($this->features->countByGameAndType($game, $type) > 0) {
            return 0;
        }

        $this->features->acquireIngestLock($game, $type);
        try {
            if ($this->features->countByGameAndType($game, $type) > 0) {
                return 0;
            }

            return $this->fetchAndProcess($query, $game, $forceType);
        } finally {
            $this->features->releaseIngestLock($game, $type);
        }
    }

    private function fetchAndProcess(string $query, Game $game, ?FeatureType $forceType = null): int
    {
        $json = $this->overpassHttpClient->fetch($query, 180, self::MAX_RESPONSE_BYTES);
        /** @var mixed $data */
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            return 0;
        }
        /** @var array<string, mixed> $data */

        if ($forceType !== null && $forceType->adminRank() !== null) {
            return $this->persistAdminDivisions($data, $game, $forceType);
        }

        return $this->persistElements($data, $game, $forceType);
    }

    /**
     * @param array<string, mixed> $data  Decoded Overpass JSON
     */
    private function persistAdminDivisions(array $data, Game $game, FeatureType $type): int
    {
        $elements = $data['elements'] ?? null;
        if (!is_array($elements)) {
            return 0;
        }

        $count = 0;
        foreach ($elements as $element) {
            if (!is_array($element) || ($element['type'] ?? null) !== 'relation') {
                continue;
            }

            $tags = $element['tags'] ?? null;
            $name = is_array($tags) ? ($tags['name'] ?? null) : null;
            $members = $element['members'] ?? null;
            if (!is_string($name) || !is_array($members)) {
                continue;
            }

            $mls = $this->relationToMultiLine($members);
            if ($mls !== null && $this->features->insertAssembledAdminDivision($game, $type, $name, $mls)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<int|string, mixed> $members
     */
    private function relationToMultiLine(array $members): ?string
    {
        $segments = [];
        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }
            $segment = $this->memberToLineSegment($member);
            if ($segment !== null) {
                $segments[] = $segment;
            }
        }

        if ($segments === []) {
            return null;
        }

        return 'MULTILINESTRING(' . implode(', ', $segments) . ')';
    }

    /**
     * @param array<array-key, mixed> $member
     */
    private function memberToLineSegment(array $member): ?string
    {
        $geometry = $member['geometry'] ?? null;
        if (($member['role'] ?? null) !== 'outer' || !is_array($geometry) || count($geometry) < 2) {
            return null;
        }

        $points = [];
        foreach ($geometry as $pt) {
            if (!is_array($pt) || !isset($pt['lat'], $pt['lon']) || !is_numeric($pt['lat']) || !is_numeric($pt['lon'])) {
                return null;
            }
            $points[] = ((float) $pt['lon']) . ' ' . ((float) $pt['lat']);
        }

        if (count($points) < 2) {
            return null;
        }

        return '(' . implode(', ', $points) . ')';
    }

    /**
     * @param array<string, mixed> $data  Decoded Overpass JSON
     */
    private function persistElements(array $data, Game $game, ?FeatureType $forceType = null): int
    {
        $elements = $data['elements'] ?? null;
        if (!is_array($elements)) {
            return 0;
        }

        $count = 0;
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (!isset($element['tags']) || !is_array($element['tags'])) {
                continue;
            }
            /** @var array<string, string> $tags */
            $tags = $element['tags'];

            $lat = null;
            $lon = null;
            if (isset($element['lat'], $element['lon'])) {
                $lat = $element['lat'];
                $lon = $element['lon'];
            }

            if ($lat === null && isset($element['center']) && is_array($element['center'])) {
                $center = $element['center'];
                $lat = $center['lat'] ?? null;
                $lon = $center['lon'] ?? null;
            }

            $featureType = $forceType ?? $this->matchFeatureType($tags);
            if ($featureType === null) {
                continue;
            }

            // Unnamed features are usually unverified artifacts; coastlines never carry names but stay playable.
            if (!isset($tags['name']) && $featureType !== FeatureType::Coastline) {
                continue;
            }

            if ($featureType === FeatureType::Mountain) {
                $ele = $tags['ele'] ?? null;
                if ($ele === null || !is_numeric($ele) || (float) $ele < 500) {
                    continue;
                }
            }

            /** @var array<string, mixed> $element */
            $geomWkt = $this->extractGeometryWkt($element, $featureType);
            if ($lat === null && $geomWkt !== null) {
                $centroid = $this->computeCentroid($element);
                if ($centroid !== null) {
                    $lat = $centroid['lat'];
                    $lon = $centroid['lon'];
                }
            }

            if ($lat === null) {
                $center = $this->boundsCenter($element);
                if ($center !== null) {
                    $lat = $center['lat'];
                    $lon = $center['lon'];
                }
            }

            if (!is_float($lat) || !is_float($lon)) {
                continue;
            }

            $feature = new Feature(
                $game,
                $featureType,
                $tags['name'] ?? null,
                new Point($lon, $lat),
                $geomWkt,
            );
            $this->features->save($feature, false);
            ++$count;

            if ($count % 500 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $reloaded = $this->entityManager->getRepository(Game::class)->find($game->getId());
                if ($reloaded === null) {
                    throw new \RuntimeException('Game entity was not found after clearing the entity manager.');
                }
                $game = $reloaded;
            }
        }

        if ($count > 0) {
            $this->entityManager->flush();
        }

        return $count;
    }

    /**
     * @param array<string, string> $tags
     */
    private function matchFeatureType(array $tags): ?FeatureType
    {
        foreach (self::TAG_MAP as $key => $valueMap) {
            if (!isset($tags[$key])) {
                continue;
            }

            $tagValue = $tags[$key];
            if (isset($valueMap[$tagValue])) {
                return $valueMap[$tagValue];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $element
     */
    private function extractGeometryWkt(array $element, FeatureType $type): ?string
    {
        $kind = $type->geometryKind();
        if ($kind === 'point') {
            return null;
        }

        $geom = $element['geometry'] ?? null;
        if (!is_array($geom) || count($geom) < 2) {
            return null;
        }

        $coords = [];
        foreach ($geom as $pt) {
            if (!is_array($pt) || !isset($pt['lat'], $pt['lon'])) {
                return null;
            }
            if (!is_numeric($pt['lat']) || !is_numeric($pt['lon'])) {
                return null;
            }
            $coords[] = ((float) $pt['lon']) . ' ' . ((float) $pt['lat']);
        }

        $isClosed = $this->isClosedRing($coords);

        if ($kind === 'areal' && $isClosed) {
            return 'POLYGON((' . implode(', ', $coords) . '))';
        }

        return 'LINESTRING(' . implode(', ', $coords) . ')';
    }

    /**
     * @param list<string> $coords
     */
    private function isClosedRing(array $coords): bool
    {
        if (count($coords) < 4) {
            return false;
        }

        $first = explode(' ', $coords[0]);
        $last = explode(' ', $coords[count($coords) - 1]);

        $dLng = (float) $first[0] - (float) $last[0];
        $dLat = (float) $first[1] - (float) $last[1];

        return sqrt($dLng * $dLng + $dLat * $dLat) < 0.0001;
    }

    /**
     * Bbox center as representative point for point-kind POIs mapped as areas.
     *
     * @param array<string, mixed> $element
     * @return array{lat: float, lon: float}|null
     */
    private function boundsCenter(array $element): ?array
    {
        $bounds = $element['bounds'] ?? null;
        if (!is_array($bounds)) {
            return null;
        }

        $minLat = $bounds['minlat'] ?? null;
        $maxLat = $bounds['maxlat'] ?? null;
        $minLon = $bounds['minlon'] ?? null;
        $maxLon = $bounds['maxlon'] ?? null;
        if (!is_numeric($minLat) || !is_numeric($maxLat) || !is_numeric($minLon) || !is_numeric($maxLon)) {
            return null;
        }

        return [
            'lat' => ((float) $minLat + (float) $maxLat) / 2.0,
            'lon' => ((float) $minLon + (float) $maxLon) / 2.0,
        ];
    }

    /**
     * @param array<string, mixed> $element
     * @return array{lat: float, lon: float}|null
     */
    private function computeCentroid(array $element): ?array
    {
        $geom = $element['geometry'] ?? null;
        if (!is_array($geom) || $geom === []) {
            return null;
        }

        $sumLat = 0.0;
        $sumLon = 0.0;
        $n = 0;
        foreach ($geom as $pt) {
            if (!is_array($pt) || !isset($pt['lat'], $pt['lon'])) {
                continue;
            }
            if (!is_numeric($pt['lat']) || !is_numeric($pt['lon'])) {
                continue;
            }
            $sumLat += (float) $pt['lat'];
            $sumLon += (float) $pt['lon'];
            ++$n;
        }

        if ($n === 0) {
            return null;
        }

        return ['lat' => $sumLat / $n, 'lon' => $sumLon / $n];
    }

    /**
     * @param list<FeatureType> $types
     */
    private function buildQueryForTypes(array $types, string $bbox): string
    {
        $lines = [];
        foreach ($types as $type) {
            $defs = self::TYPE_DEFINITIONS[$type->value] ?? null;
            if ($defs === null) {
                throw new \RuntimeException(
                    sprintf('Feature type "%s" has no Overpass query mapping.', $type->value),
                );
            }
            foreach ($defs as $def) {
                $elementType = $def[0];
                $key = $def[1];
                $value = $def[2];
                $extraTags = $def[3] ?? [];

                $filters = sprintf('["%s"="%s"]', $key, $value);
                foreach ($extraTags as $extraKey => $extraValue) {
                    /** @phpstan-ignore identical.alwaysTrue (value arm kept: a definition may match a value) */
                    $filters .= $extraValue === ''
                        ? sprintf('["%s"]', $extraKey)
                        : sprintf('["%s"="%s"]', $extraKey, $extraValue);
                }
                $lines[] = sprintf('  %s%s(%s);', $elementType, $filters, $bbox);
            }
        }

        $body = implode("\n", $lines);

        return <<<QL
            [out:json][timeout:180];
            (
            {$body}
            );
            out geom;
        QL;
    }

    private function buildAdminQuery(int $level, string $bbox): string
    {
        $line = sprintf('  relation["boundary"="administrative"]["admin_level"="%d"](%s);', $level, $bbox);

        return <<<QL
            [out:json][timeout:180];
            (
            {$line}
            );
            out geom;
        QL;
    }

    private function buildBorderQuery(int $level, string $bbox): string
    {
        $rel = sprintf('  relation["boundary"="administrative"]["admin_level"="%d"](%s);', $level, $bbox);

        return <<<QL
            [out:json][timeout:180];
            {$rel}
            way(r)({$bbox});
            out geom;
        QL;
    }

    /**
     * @return list<FeatureType>
     */
    private function allQueryableTypes(): array
    {
        return array_map(
            static fn (string $value): FeatureType => FeatureType::from($value),
            array_keys(self::TYPE_DEFINITIONS),
        );
    }

    private function buildQuery(string $bbox): string
    {
        return $this->buildQueryForTypes($this->allQueryableTypes(), $bbox);
    }
}
