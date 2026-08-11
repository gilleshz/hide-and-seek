<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NominatimService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(NominatimService::class)]
final class NominatimServiceTest extends TestCase
{
    private const string SEARCH_RESPONSE = <<<'JSON'
    [{"osm_type":"relation","osm_id":123,"display_name":"Testville",
      "category":"boundary","type":"administrative","address":{"admin_level":"8"}}]
    JSON;

    private const string REVERSE_RESPONSE =
        '{"address":{"country_code":"ch"}}';

    private const string LOOKUP_RESPONSE = <<<'JSON'
    [{"osm_type":"relation","osm_id":123,"display_name":"Testville",
      "address":{"admin_level":"8"},
      "geojson":{"type":"Polygon","coordinates":[[[6.60,46.50],[6.65,46.50],[6.65,46.55],[6.60,46.55],[6.60,46.50]]]}}]
    JSON;

    /** @param array<string, mixed>|null $options */
    private function serviceWithCapture(MockResponse $response, ?array &$options): NominatimService
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $requestOptions) use (&$options, $response): MockResponse {
            $options = $requestOptions;

            return $response;
        });

        return new NominatimService($httpClient, new ArrayAdapter(), 'JetLag/1.0', 'https://nominatim.example.org');
    }

    #[Test]
    public function searchAreasSendsHardCapsAndParsesResults(): void
    {
        $options = null;
        $service = $this->serviceWithCapture(new MockResponse(self::SEARCH_RESPONSE), $options);

        $areas = $service->searchAreas('Testville');

        self::assertSame(15.0, $options['timeout'] ?? null);
        self::assertSame(0, $options['max_redirects'] ?? null);
        self::assertCount(1, $areas);
        $area = $areas[0];
        self::assertSame('relation', $area->osmType);
        self::assertSame(123, $area->osmId);
        self::assertSame('Testville', $area->displayName);
        self::assertSame(8, $area->adminLevel);
    }

    #[Test]
    public function searchAreasReturnsEmptyForBlankQuery(): void
    {
        $options = null;
        $service = $this->serviceWithCapture(new MockResponse(self::SEARCH_RESPONSE), $options);

        self::assertSame([], $service->searchAreas('   '));
    }

    #[Test]
    public function reverseCountryCodeSendsHardCapsAndParsesResult(): void
    {
        $options = null;
        $service = $this->serviceWithCapture(new MockResponse(self::REVERSE_RESPONSE), $options);

        $code = $service->reverseCountryCode(46.5, 6.6);

        self::assertSame(15.0, $options['timeout'] ?? null);
        self::assertSame(0, $options['max_redirects'] ?? null);
        self::assertSame('CH', $code);
    }

    #[Test]
    public function fetchAreaGeometrySendsHardCapsAndParsesResults(): void
    {
        $options = null;
        $service = $this->serviceWithCapture(new MockResponse(self::LOOKUP_RESPONSE), $options);

        $geometries = $service->fetchAreaGeometry([['osmType' => 'relation', 'osmId' => 123]]);

        self::assertSame(15.0, $options['timeout'] ?? null);
        self::assertSame(0, $options['max_redirects'] ?? null);
        self::assertCount(1, $geometries);
        $geometry = $geometries[0];
        self::assertSame('relation', $geometry['osmType']);
        self::assertSame(123, $geometry['osmId']);
        self::assertSame(8, $geometry['adminLevel']);
        self::assertStringContainsString('Polygon', $geometry['geoJson']);
    }

    #[Test]
    public function itRejectsResponsesOverTheSizeCap(): void
    {
        $options = null;
        $service = $this->serviceWithCapture(new MockResponse(str_repeat('a', 10_000_001)), $options);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('size cap');

        $service->searchAreas('Testville');
    }

    #[Test]
    public function itRejectsInvalidJson(): void
    {
        $options = null;
        $service = $this->serviceWithCapture(new MockResponse('not json'), $options);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');

        $service->searchAreas('Testville');
    }
}
