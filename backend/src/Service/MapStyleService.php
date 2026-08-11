<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MapStyleService
{
    private const string STADIA_HEALTH_URL = 'https://tiles.stadiamaps.com/styles/osm_bright.json';
    private const string THUNDERFOREST_HEALTH_URL = 'https://tile.thunderforest.com/atlas/0/0/0.png';
    private const string MAPTILER_HEALTH_URL = 'https://api.maptiler.com/maps/hybrid/style.json';
    private const int CACHE_TTL = 3600;

    /**
     * @param array<string, string> $apiKeys keyed by provider (stadia, thunderforest, maptiler)
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire('%app.map_provider_keys%')]
        private array $apiKeys,
    ) {
    }

    public function stadiaApiKey(): ?string
    {
        return $this->providerKey('stadia');
    }

    public function thunderforestApiKey(): ?string
    {
        return $this->providerKey('thunderforest');
    }

    public function maptilerApiKey(): ?string
    {
        return $this->providerKey('maptiler');
    }

    public function stadiaAvailable(): bool
    {
        return $this->available($this->stadiaApiKey(), self::STADIA_HEALTH_URL, 'api_key');
    }

    public function thunderforestAvailable(): bool
    {
        return $this->available($this->thunderforestApiKey(), self::THUNDERFOREST_HEALTH_URL, 'apikey');
    }

    public function maptilerAvailable(): bool
    {
        return $this->available($this->maptilerApiKey(), self::MAPTILER_HEALTH_URL, 'key');
    }

    /** @return list<string> */
    public function availableStyles(): array
    {
        $styles = [];

        if ($this->stadiaAvailable()) {
            $styles[] = 'osm_bright';
        }

        if ($this->thunderforestAvailable()) {
            $styles[] = 'thunderforest_atlas';
        }

        if ($this->maptilerAvailable()) {
            $styles[] = 'maptiler_hybrid';
        }

        return $styles;
    }

    private function providerKey(string $provider): ?string
    {
        $key = trim($this->apiKeys[$provider] ?? '');

        return $key === '' ? null : $key;
    }

    private function available(?string $key, string $url, string $queryParam): bool
    {
        if ($key === null) {
            return false;
        }

        $cacheKey = 'map_health_' . md5($url . $key);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($key, $url, $queryParam): bool {
            $item->expiresAfter(self::CACHE_TTL);

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'query' => [$queryParam => $key],
                    'timeout' => 5.0,
                ]);

                return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
            } catch (\Throwable) {
                return false;
            }
        });
    }
}
