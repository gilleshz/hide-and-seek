<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MapStyleService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;

#[CoversClass(MapStyleService::class)]
final class MapStyleServiceTest extends TestCase
{
    private const string VALID_STADIA_KEY = 'test-stadia-key';
    private const string VALID_THUNDERFOREST_KEY = 'test-thunderforest-key';
    private const string VALID_MAPTILER_KEY = 'test-maptiler-key';

    #[Test]
    public function stadiaApiKeyReturnsNullWhenBlank(): void
    {
        $service = $this->createService(stadiaKey: '  ');

        self::assertNull($service->stadiaApiKey());
    }

    #[Test]
    public function stadiaApiKeyReturnsTrimmedValue(): void
    {
        $service = $this->createService(stadiaKey: '  my-key  ');

        self::assertSame('my-key', $service->stadiaApiKey());
    }

    #[Test]
    public function stadiaAvailableReturnsFalseWhenKeyIsBlank(): void
    {
        $service = $this->createService(stadiaKey: '');

        self::assertFalse($service->stadiaAvailable());
    }

    #[Test]
    public function stadiaAvailableReturnsTrueOn200(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 200]),
        ]);
        $service = $this->createService(stadiaKey: self::VALID_STADIA_KEY, http: $http);

        self::assertTrue($service->stadiaAvailable());
    }

    #[Test]
    public function stadiaAvailableReturnsFalseOn401(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 401]),
        ]);
        $service = $this->createService(stadiaKey: self::VALID_STADIA_KEY, http: $http);

        self::assertFalse($service->stadiaAvailable());
    }

    #[Test]
    public function stadiaAvailableReturnsFalseOnNetworkError(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['error' => 'connection refused']),
        ]);
        $service = $this->createService(stadiaKey: self::VALID_STADIA_KEY, http: $http);

        self::assertFalse($service->stadiaAvailable());
    }

    #[Test]
    public function stadiaAvailableCachesResult(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 500]),
        ]);
        $stored = null;
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            function (string $key, callable $callback) use (&$stored) {
                if ($stored !== null) {
                    return $stored;
                }
                $stored = $callback($this->createStub(\Symfony\Contracts\Cache\ItemInterface::class));

                return $stored;
            },
        );

        $service = new MapStyleService($http, $cache, ['stadia' => self::VALID_STADIA_KEY]);

        self::assertTrue($service->stadiaAvailable());
        self::assertTrue($service->stadiaAvailable());
    }

    #[Test]
    public function thunderforestApiKeyReturnsNullWhenBlank(): void
    {
        $service = $this->createService(thunderforestKey: '  ');

        self::assertNull($service->thunderforestApiKey());
    }

    #[Test]
    public function thunderforestApiKeyReturnsTrimmedValue(): void
    {
        $service = $this->createService(thunderforestKey: '  tf-key  ');

        self::assertSame('tf-key', $service->thunderforestApiKey());
    }

    #[Test]
    public function thunderforestAvailableReturnsFalseWhenKeyIsBlank(): void
    {
        $service = $this->createService(thunderforestKey: '');

        self::assertFalse($service->thunderforestAvailable());
    }

    #[Test]
    public function thunderforestAvailableReturnsTrueOn200(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 200]),
        ]);
        $service = $this->createService(thunderforestKey: self::VALID_THUNDERFOREST_KEY, http: $http);

        self::assertTrue($service->thunderforestAvailable());
    }

    #[Test]
    public function thunderforestAvailableReturnsFalseOn403(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 403]),
        ]);
        $service = $this->createService(thunderforestKey: self::VALID_THUNDERFOREST_KEY, http: $http);

        self::assertFalse($service->thunderforestAvailable());
    }

    #[Test]
    public function availableStylesReturnsStadiaWhenAvailable(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 403]),
        ]);
        $service = $this->createService(
            stadiaKey: self::VALID_STADIA_KEY,
            thunderforestKey: self::VALID_THUNDERFOREST_KEY,
            http: $http,
        );

        $styles = $service->availableStyles();

        self::assertContains('osm_bright', $styles);
        self::assertNotContains('thunderforest_atlas', $styles);
    }

    #[Test]
    public function maptilerApiKeyReturnsTrimmedValue(): void
    {
        $service = $this->createService(maptilerKey: '  mt-key  ');

        self::assertSame('mt-key', $service->maptilerApiKey());
    }

    #[Test]
    public function maptilerAvailableReturnsFalseWhenKeyIsBlank(): void
    {
        $service = $this->createService(maptilerKey: '');

        self::assertFalse($service->maptilerAvailable());
    }

    #[Test]
    public function maptilerAvailableReturnsTrueOn200(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 200]),
        ]);
        $service = $this->createService(maptilerKey: self::VALID_MAPTILER_KEY, http: $http);

        self::assertTrue($service->maptilerAvailable());
    }

    #[Test]
    public function availableStylesReturnsMaptilerWhenAvailable(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 401]),
            new MockResponse('', ['http_code' => 403]),
            new MockResponse('{}', ['http_code' => 200]),
        ]);
        $service = $this->createService(
            stadiaKey: self::VALID_STADIA_KEY,
            thunderforestKey: self::VALID_THUNDERFOREST_KEY,
            maptilerKey: self::VALID_MAPTILER_KEY,
            http: $http,
        );

        $styles = $service->availableStyles();

        self::assertContains('maptiler_hybrid', $styles);
        self::assertNotContains('osm_bright', $styles);
        self::assertNotContains('thunderforest_atlas', $styles);
    }

    #[Test]
    public function availableStylesReturnsThunderforestWhenAvailable(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 401]),
            new MockResponse('', ['http_code' => 200]),
        ]);
        $service = $this->createService(
            stadiaKey: self::VALID_STADIA_KEY,
            thunderforestKey: self::VALID_THUNDERFOREST_KEY,
            http: $http,
        );

        $styles = $service->availableStyles();

        self::assertContains('thunderforest_atlas', $styles);
        self::assertNotContains('osm_bright', $styles);
    }

    #[Test]
    public function availableStylesReturnsEmptyWhenNoneAvailable(): void
    {
        $service = $this->createService(stadiaKey: '', thunderforestKey: '');

        $styles = $service->availableStyles();

        self::assertEmpty($styles);
    }

    private function createService(
        string $stadiaKey = '',
        string $thunderforestKey = '',
        string $maptilerKey = '',
        ?MockHttpClient $http = null,
    ): MapStyleService {
        $httpClient = $http ?? new MockHttpClient([]);
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(function (string $key, callable $callback) {
            return $callback($this->createStub(\Symfony\Contracts\Cache\ItemInterface::class));
        });

        return new MapStyleService($httpClient, $cache, [
            'stadia' => $stadiaKey,
            'thunderforest' => $thunderforestKey,
            'maptiler' => $maptilerKey,
        ]);
    }
}
