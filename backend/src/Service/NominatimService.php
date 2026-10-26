<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AreaResult;
use App\ErrorKey;
use App\Exception\FunctionalException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class NominatimService
{
    private const int MAX_RESPONSE_BYTES = 10 * 1_000_000;

    /** The public lookup endpoint accepts at most 50 osm_ids per request, and one covers every area. */
    public const int MAX_LOOKUP_IDS = 50;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire('%app.nominatim_user_agent%')]
        private string $userAgent,
        #[Autowire('%app.nominatim_base_url%')]
        private string $baseUrl,
    ) {
    }

    /** @return AreaResult[] */
    public function searchAreas(string $query): array
    {
        $normalized = mb_strtolower(trim($query));
        if ($normalized === '') {
            return [];
        }

        // Cached values are serialized AreaResult objects: bump the prefix whenever their shape changes.
        return $this->cache->get('nominatim_search_v2_' . md5($normalized), function (ItemInterface $item) use ($query): array {
            $item->expiresAfter(86400);

            $response = $this->httpClient->request('GET', $this->baseUrl . '/search', [
                'query' => [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'addressdetails' => '1',
                    'extratags' => '1',
                    'limit' => '10',
                    'polygon_geojson' => '0',
                ],
                'headers' => ['User-Agent' => $this->userAgent],
                'timeout' => 15,
                'max_redirects' => 0,
            ]);

            $results = $this->decode($response);
            if (!is_array($results)) {
                throw new \RuntimeException('Nominatim returned an invalid search response.');
            }
            /** @var list<array<string, mixed>> $results */
            $areas = [];
            foreach ($results as $r) {
                $osmType = isset($r['osm_type']) && is_string($r['osm_type']) ? $r['osm_type'] : '';
                $categoryRaw = $r['category'] ?? $r['class'] ?? '';
                $class = is_string($categoryRaw) ? $categoryRaw : '';
                $type = isset($r['type']) && is_string($r['type']) ? $r['type'] : '';
                if ($osmType !== 'relation') {
                    continue;
                }
                if (!(($class === 'boundary' && $type === 'administrative') || $class === 'place')) {
                    continue;
                }
                $address = isset($r['address']) && is_array($r['address']) ? $r['address'] : [];
                $extratags = isset($r['extratags']) && is_array($r['extratags']) ? $r['extratags'] : [];
                $adminLevel = self::adminLevel($address, $extratags);
                $osmId = isset($r['osm_id']) && is_numeric($r['osm_id']) ? (int) $r['osm_id'] : 0;
                $displayName = isset($r['display_name']) && is_string($r['display_name']) ? $r['display_name'] : '';
                [$widthKm, $heightKm] = self::bboxSizes($r['boundingbox'] ?? null);
                $areas[] = new AreaResult(
                    osmType: $osmType,
                    osmId: $osmId,
                    displayName: $displayName,
                    adminLevel: $adminLevel,
                    population: self::population($extratags),
                    widthKm: $widthKm,
                    heightKm: $heightKm,
                );
            }

            return self::groupAndOrder($areas);
        });
    }

    public function reverseCountryCode(float $lat, float $lng): ?string
    {
        return $this->cache->get('nominatim_country_' . md5($lat . ',' . $lng), function (ItemInterface $item) use ($lat, $lng): ?string {
            $item->expiresAfter(86400);

            $response = $this->httpClient->request('GET', $this->baseUrl . '/reverse', [
                'query' => [
                    'format' => 'json',
                    'addressdetails' => '1',
                    'lat' => (string) $lat,
                    'lon' => (string) $lng,
                ],
                'headers' => ['User-Agent' => $this->userAgent],
                'timeout' => 15,
                'max_redirects' => 0,
            ]);

            $result = $this->decode($response);
            if (!is_array($result)) {
                throw new \RuntimeException('Nominatim returned an invalid reverse response.');
            }
            /** @var array<string, mixed> $result */
            $address = isset($result['address']) && is_array($result['address']) ? $result['address'] : [];
            $code = $address['country_code'] ?? null;

            return is_string($code) ? mb_strtoupper($code) : null;
        });
    }

    /** @param list<array{osmType: string, osmId: int}> $refs
     *  @return list<array{osmType: string, osmId: int, name: string, adminLevel: int|null, geoJson: string}> */
    public function fetchAreaGeometry(array $refs): array
    {
        if ($refs === []) {
            return [];
        }

        if (count($refs) > self::MAX_LOOKUP_IDS) {
            throw new FunctionalException(
                message: sprintf('At most %d areas can be selected.', self::MAX_LOOKUP_IDS),
                errorKey: ErrorKey::TOO_MANY_AREAS,
                errorArgs: ['max' => (string) self::MAX_LOOKUP_IDS],
            );
        }

        $ids = implode(',', array_map(fn(array $r): string => match ($r['osmType']) {
            'relation' => 'R' . $r['osmId'],
            'way' => 'W' . $r['osmId'],
            default => 'N' . $r['osmId'],
        }, $refs));

        // Simplifying each polygon here misaligns shared borders, so the union leaves sliver holes.
        $response = $this->httpClient->request('GET', $this->baseUrl . '/lookup', [
            'query' => [
                'osm_ids' => $ids,
                'format' => 'json',
                'extratags' => '1',
                'polygon_geojson' => '1',
            ],
            'headers' => ['User-Agent' => $this->userAgent],
            'timeout' => 15,
            'max_redirects' => 0,
        ]);

        $results = $this->decode($response);
        if (!is_array($results)) {
            throw new \RuntimeException('Nominatim returned an invalid lookup response.');
        }
        /** @var list<array<string, mixed>> $results */
        $geometries = [];
        foreach ($results as $r) {
            $geoJson = $r['geojson'] ?? null;
            if (!is_array($geoJson) || ($geoJson['type'] ?? '') === 'Point') {
                continue;
            }
            $address = isset($r['address']) && is_array($r['address']) ? $r['address'] : [];
            $extratags = isset($r['extratags']) && is_array($r['extratags']) ? $r['extratags'] : [];
            $adminLevel = self::adminLevel($address, $extratags);
            $osmType = isset($r['osm_type']) && is_string($r['osm_type']) ? $r['osm_type'] : 'relation';
            $osmId = isset($r['osm_id']) && is_numeric($r['osm_id']) ? (int) $r['osm_id'] : 0;
            $name = isset($r['display_name']) && is_string($r['display_name'])
                ? $r['display_name']
                : (isset($r['localname']) && is_string($r['localname']) ? $r['localname'] : '');
            $geometries[] = [
                'osmType' => $osmType,
                'osmId' => $osmId,
                'name' => $name,
                'adminLevel' => $adminLevel,
                'geoJson' => json_encode($geoJson, JSON_THROW_ON_ERROR),
            ];
        }

        return $geometries;
    }

    /**
     * @param array<mixed> $address
     * @param array<mixed> $extratags
     */
    private static function adminLevel(array $address, array $extratags): ?int
    {
        foreach ([$extratags['admin_level'] ?? null, $address['admin_level'] ?? null] as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /** @param array<mixed> $extratags */
    private static function population(array $extratags): ?int
    {
        $raw = $extratags['population'] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $raw);

        return $digits === null || $digits === '' ? null : (int) $digits;
    }

    /** @return array{0: ?float, 1: ?float} width then height in km */
    private static function bboxSizes(mixed $bbox): array
    {
        $values = [];
        if (is_array($bbox)) {
            foreach ($bbox as $value) {
                if (!is_numeric($value)) {
                    return [null, null];
                }
                $values[] = (float) $value;
            }
        }

        if (
            !isset($values[0], $values[1], $values[2], $values[3])
            || count($values) !== 4
            || $values[0] > $values[1]
            || $values[2] > $values[3]
        ) {
            return [null, null];
        }

        return [
            round(($values[3] - $values[2]) * 111.32 * cos(deg2rad(($values[0] + $values[1]) / 2)), 1),
            round(($values[1] - $values[0]) * 111.32, 1),
        ];
    }

    /**
     * Same-named places in the same country are ranked as one block at the best member's position,
     * smallest first, so a commune and its wider area stay adjacent instead of being split by
     * unrelated same-name results.
     *
     * @param list<AreaResult> $areas
     * @return list<AreaResult>
     */
    private static function groupAndOrder(array $areas): array
    {
        $groups = [];
        $order = [];
        foreach ($areas as $index => $area) {
            $key = self::groupKey($area->displayName);
            $order[$key] ??= $index;
            $groups[$key][] = $area;
        }

        uksort($groups, static fn (int|string $a, int|string $b): int => $order[(string) $a] <=> $order[(string) $b]);

        $ordered = [];
        foreach ($groups as $group) {
            usort($group, static function (AreaResult $a, AreaResult $b): int {
                $areaA = $a->widthKm !== null && $a->heightKm !== null ? $a->widthKm * $a->heightKm : null;
                $areaB = $b->widthKm !== null && $b->heightKm !== null ? $b->widthKm * $b->heightKm : null;
                if ($areaA === null || $areaB === null) {
                    return ($areaA === null) <=> ($areaB === null);
                }

                return $areaA <=> $areaB;
            });
            foreach ($group as $area) {
                $ordered[] = $area;
            }
        }

        return $ordered;
    }

    private static function groupKey(string $displayName): string
    {
        $parts = array_map(static fn (string $part): string => mb_strtolower(trim($part)), explode(',', $displayName));

        return $parts[0] . '|' . (end($parts) ?: '');
    }

    private function decode(ResponseInterface $response): mixed
    {
        $buffer = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $buffer .= $chunk->getContent();
            if (strlen($buffer) > self::MAX_RESPONSE_BYTES) {
                throw new \RuntimeException('Nominatim response exceeded the size cap (10 MB).');
            }
        }

        try {
            return json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Nominatim returned an invalid JSON response.');
        }
    }
}
