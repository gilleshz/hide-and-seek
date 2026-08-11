<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AreaResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class NominatimService
{
    private const int MAX_RESPONSE_BYTES = 10 * 1_000_000;

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

        return $this->cache->get('nominatim_search_' . md5($normalized), function (ItemInterface $item) use ($query): array {
            $item->expiresAfter(86400);

            $response = $this->httpClient->request('GET', $this->baseUrl . '/search', [
                'query' => [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'addressdetails' => '1',
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
                $adminLevel = null;
                if (isset($address['admin_level']) && is_numeric($address['admin_level'])) {
                    $adminLevel = (int) $address['admin_level'];
                }
                $osmId = isset($r['osm_id']) && is_numeric($r['osm_id']) ? (int) $r['osm_id'] : 0;
                $displayName = isset($r['display_name']) && is_string($r['display_name']) ? $r['display_name'] : '';
                $areas[] = new AreaResult(
                    osmType: $osmType,
                    osmId: $osmId,
                    displayName: $displayName,
                    adminLevel: $adminLevel,
                );
            }

            return $areas;
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

        $ids = implode(',', array_map(fn(array $r): string => match ($r['osmType']) {
            'relation' => 'R' . $r['osmId'],
            'way' => 'W' . $r['osmId'],
            default => 'N' . $r['osmId'],
        }, $refs));

        $response = $this->httpClient->request('GET', $this->baseUrl . '/lookup', [
            'query' => [
                'osm_ids' => $ids,
                'format' => 'json',
                'polygon_geojson' => '1',
                'polygon_threshold' => '0.0001',
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
            $adminLevel = isset($address['admin_level']) && is_numeric($address['admin_level'])
                ? (int) $address['admin_level'] : null;
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
