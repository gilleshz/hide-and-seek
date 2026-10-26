<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\AreaResult;
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
    [{"osm_type":"relation","osm_id":123,"display_name":"Testville, Testland",
      "category":"boundary","type":"administrative",
      "extratags":{"admin_level":"8","population":"120874"},
      "boundingbox":["46.50","46.60","6.60","6.70"]}]
    JSON;

    private const string LYON_SEARCH_RESPONSE = <<<'JSON'
    [
      {"osm_type":"relation","osm_id":1,"display_name":"Lyon, Métropole de Lyon, Rhône, France",
       "category":"boundary","type":"administrative","extratags":{"admin_level":"8"},
       "boundingbox":["45.70","45.80","4.80","4.90"]},
      {"osm_type":"relation","osm_id":2,"display_name":"Lyon County, Iowa, United States",
       "category":"boundary","type":"administrative","extratags":{"admin_level":"6"},
       "boundingbox":["43.00","43.50","-96.50","-96.00"]},
      {"osm_type":"relation","osm_id":3,"display_name":"Lyon, Rhône, France",
       "category":"boundary","type":"administrative","extratags":{"admin_level":"6"},
       "boundingbox":["45.50","46.00","4.50","5.00"]}
    ]
    JSON;

    private const string METZ_SEARCH_RESPONSE = <<<'JSON'
    [
      {"osm_type":"relation","osm_id":10,"display_name":"Metz, Moselle, Grand Est, France",
       "category":"boundary","type":"administrative","extratags":{"admin_level":"8"},
       "boundingbox":["49.06","49.15","6.13","6.26"]},
      {"osm_type":"relation","osm_id":11,
       "display_name":"Metz Township, Presque Isle County, Michigan, United States",
       "category":"boundary","type":"administrative","extratags":{"admin_level":"8"},
       "boundingbox":["45.20","45.29","-83.89","-83.76"]},
      {"osm_type":"relation","osm_id":12,"display_name":"Metz, Moselle, Grand Est, France",
       "category":"boundary","type":"administrative","extratags":{"admin_level":"7"},
       "boundingbox":["48.90","49.27","5.92","6.50"]}
    ]
    JSON;

    private const string REVERSE_RESPONSE =
        '{"address":{"country_code":"ch"}}';

    private const string LOOKUP_RESPONSE = <<<'JSON'
    [{"osm_type":"relation","osm_id":123,"display_name":"Testville",
      "extratags":{"admin_level":"8"},
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
        $query = $options['query'] ?? null;
        self::assertIsArray($query);
        self::assertSame('1', $query['extratags'] ?? null);
        self::assertCount(1, $areas);
        $area = $areas[0];
        self::assertSame('relation', $area->osmType);
        self::assertSame(123, $area->osmId);
        self::assertSame('Testville, Testland', $area->displayName);
        self::assertSame(8, $area->adminLevel);
        self::assertSame(120874, $area->population);
        self::assertEqualsWithDelta(7.7, $area->widthKm, 0.05);
        self::assertSame(11.1, $area->heightKm);
    }

    #[Test]
    public function searchAreasGroupsSameNamedPlacesAtTheBestRankAndSmallestFirst(): void
    {
        $options = null;
        $service = $this->serviceWithCapture(new MockResponse(self::LYON_SEARCH_RESPONSE), $options);

        $areas = $service->searchAreas('Lyon');

        self::assertSame(
            [1, 3, 2],
            array_map(static fn (AreaResult $area): int => $area->osmId, $areas),
        );
    }

    #[Test]
    public function searchAreasGroupsIdenticalDisplayNames(): void
    {
        $options = null;
        $service = $this->serviceWithCapture(new MockResponse(self::METZ_SEARCH_RESPONSE), $options);

        $areas = $service->searchAreas('Metz');

        self::assertSame(
            [10, 12, 11],
            array_map(static fn (AreaResult $area): int => $area->osmId, $areas),
        );
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
        $query = $options['query'] ?? null;
        self::assertIsArray($query);
        self::assertSame('1', $query['extratags'] ?? null);
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
