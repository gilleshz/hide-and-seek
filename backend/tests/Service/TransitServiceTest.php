<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BoundaryService;
use App\Service\OverpassHttpClient;
use App\Service\TransitService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(TransitService::class)]
final class TransitServiceTest extends TestCase
{
    #[Test]
    public function itDiscoversLinesFromBbox(): void
    {
        $fixture = json_encode([
            'elements' => [
                [
                    'type' => 'relation',
                    'id' => 100,
                    'tags' => [
                        'route' => 'subway',
                        'ref' => 'A',
                        'name' => 'Line A',
                        'colour' => '#FF0000',
                        'network' => 'Metro',
                        'operator' => 'TransitCo',
                    ],
                ],
                [
                    'type' => 'relation',
                    'id' => 101,
                    'tags' => [
                        'route' => 'tram',
                        'ref' => 'B',
                        'name' => 'Line B',
                        'colour' => '#0000FF',
                        'network' => 'CityTram',
                        'operator' => 'TransitCo',
                    ],
                ],
                [
                    'type' => 'node',
                    'id' => 999,
                    'tags' => ['route' => 'bus', 'ref' => 'X'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($fixture));

        $boundaryService = new class ('48.0,2.0,49.0,3.0') extends BoundaryService {
            public function __construct(
                private readonly string $bbox,
            ) {
            }

            public function computeBbox(array $areas): string
            {
                return $this->bbox;
            }
        };

        $service = new TransitService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), 32_000_000, $boundaryService);

        $area = ['osmType' => 'relation', 'osmId' => 123];

        $lines = $service->discoverLines([$area]);

        self::assertCount(2, $lines);
        self::assertSame('100', $lines[0]['osmId']);
        self::assertSame('subway', $lines[0]['routeType']);
        self::assertSame('Metro', $lines[0]['network']);
        self::assertSame('101', $lines[1]['osmId']);
        self::assertSame('tram', $lines[1]['routeType']);
        self::assertSame('CityTram', $lines[1]['network']);
    }

    #[Test]
    public function itReturnsEmptyDiscoveryWhenBboxIsNull(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $boundaryService = new class extends BoundaryService {
            public function __construct()
            {
            }

            public function computeBbox(array $areas): ?string
            {
                return null;
            }
        };

        $service = new TransitService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), 32_000_000, $boundaryService);

        $area = ['osmType' => 'relation', 'osmId' => 123];

        $lines = $service->discoverLines([$area]);

        self::assertSame([], $lines);
    }

    #[Test]
    public function itFiltersLinesWithEmptyRefAndName(): void
    {
        $fixture = json_encode([
            'elements' => [
                [
                    'type' => 'relation',
                    'id' => 200,
                    'tags' => ['route' => 'tram', 'ref' => '', 'name' => ''],
                ],
                [
                    'type' => 'relation',
                    'id' => 201,
                    'tags' => ['route' => 'subway', 'ref' => 'M1', 'name' => 'Metro 1'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($fixture));

        $boundaryService = new class ('48.0,2.0,49.0,3.0') extends BoundaryService {
            public function __construct(private readonly string $bbox)
            {
            }

            public function computeBbox(array $areas): string
            {
                return $this->bbox;
            }
        };

        $service = new TransitService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), 32_000_000, $boundaryService);

        $area = ['osmType' => 'relation', 'osmId' => 123];
        $lines = $service->discoverLines([$area]);

        self::assertCount(1, $lines);
        self::assertSame('M1', $lines[0]['ref']);
    }

    #[Test]
    public function itFiltersNonBusLinesWithEmptyRef(): void
    {
        $fixture = json_encode([
            'elements' => [
                [
                    'type' => 'relation',
                    'id' => 300,
                    'tags' => ['route' => 'tram', 'ref' => '', 'name' => 'Historic Tramway'],
                ],
                [
                    'type' => 'relation',
                    'id' => 301,
                    'tags' => ['route' => 'train', 'ref' => '', 'name' => 'Scenic Railway'],
                ],
                [
                    'type' => 'relation',
                    'id' => 302,
                    'tags' => ['route' => 'subway', 'ref' => 'U2', 'name' => 'U-Bahn 2'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($fixture));

        $boundaryService = new class ('48.0,2.0,49.0,3.0') extends BoundaryService {
            public function __construct(private readonly string $bbox)
            {
            }

            public function computeBbox(array $areas): string
            {
                return $this->bbox;
            }
        };

        $service = new TransitService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), 32_000_000, $boundaryService);

        $area = ['osmType' => 'relation', 'osmId' => 123];
        $lines = $service->discoverLines([$area]);

        self::assertCount(1, $lines);
        self::assertSame('U2', $lines[0]['ref']);
    }

    #[Test]
    public function itKeepsBusLinesWithEmptyRef(): void
    {
        $fixture = json_encode([
            'elements' => [
                [
                    'type' => 'relation',
                    'id' => 400,
                    'tags' => ['route' => 'bus', 'ref' => '', 'name' => 'Rural Bus'],
                ],
                [
                    'type' => 'relation',
                    'id' => 401,
                    'tags' => ['route' => 'bus', 'ref' => '42', 'name' => 'Bus 42'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($fixture));

        $boundaryService = new class ('48.0,2.0,49.0,3.0') extends BoundaryService {
            public function __construct(private readonly string $bbox)
            {
            }

            public function computeBbox(array $areas): string
            {
                return $this->bbox;
            }
        };

        $service = new TransitService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), 32_000_000, $boundaryService);

        $area = ['osmType' => 'relation', 'osmId' => 123];
        $lines = $service->discoverLines([$area]);

        self::assertCount(2, $lines);
    }

    #[Test]
    public function itAssignsFallbackColourWhenEmpty(): void
    {
        $fixture = json_encode([
            'elements' => [
                [
                    'type' => 'relation',
                    'id' => 500,
                    'tags' => ['route' => 'tram', 'ref' => 'T1', 'name' => 'Tram 1'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($fixture));

        $boundaryService = new class ('48.0,2.0,49.0,3.0') extends BoundaryService {
            public function __construct(private readonly string $bbox)
            {
            }

            public function computeBbox(array $areas): string
            {
                return $this->bbox;
            }
        };

        $service = new TransitService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), 32_000_000, $boundaryService);

        $area = ['osmType' => 'relation', 'osmId' => 123];
        $lines = $service->discoverLines([$area]);

        self::assertCount(1, $lines);
        self::assertNotEmpty($lines[0]['colour']);
        self::assertStringStartsWith('#', $lines[0]['colour']);
        self::assertSame(7, strlen($lines[0]['colour']));
    }

    #[Test]
    public function itBuildsAPreviewFeaturePerSelectedLine(): void
    {
        $fixture = json_encode([
            'elements' => [
                [
                    'type' => 'relation',
                    'id' => 700,
                    'tags' => ['route' => 'tram', 'ref' => 'A', 'name' => 'Tram A', 'colour' => '#00AA00'],
                    'members' => [
                        [
                            'type' => 'way',
                            'role' => '',
                            'geometry' => [
                                ['lat' => 48.1, 'lon' => 7.1],
                                ['lat' => 48.2, 'lon' => 7.2],
                            ],
                        ],
                        [
                            'type' => 'way',
                            'role' => '',
                            'geometry' => [
                                ['lat' => 48.2, 'lon' => 7.2],
                                ['lat' => 48.3, 'lon' => 7.3],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $feature = $this->onlyPreviewFeature($this->serviceReturning($fixture)->previewGeometry(['700']));

        $geometry = $feature['geometry'];
        self::assertIsArray($geometry);
        self::assertSame('MultiLineString', $geometry['type']);
        $coordinates = $geometry['coordinates'];
        self::assertIsArray($coordinates);
        self::assertCount(2, $coordinates);
        $firstLine = $coordinates[0];
        self::assertIsArray($firstLine);
        self::assertSame([7.1, 48.1], $firstLine[0]);

        $properties = $feature['properties'];
        self::assertIsArray($properties);
        self::assertSame('700', $properties['osmId']);
        self::assertSame('A', $properties['ref']);
        self::assertSame('#00AA00', $properties['colour']);
        self::assertSame('tram', $properties['routeType']);
    }

    /** @return array<array-key, mixed> */
    private function onlyPreviewFeature(?string $geoJson): array
    {
        self::assertNotNull($geoJson);
        $decoded = json_decode($geoJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('FeatureCollection', $decoded['type'] ?? null);
        $features = $decoded['features'] ?? null;
        self::assertIsArray($features);
        self::assertCount(1, $features);
        $feature = $features[0];
        self::assertIsArray($feature);

        return $feature;
    }

    #[Test]
    public function itSkipsStopAndPlatformMembersInAPreview(): void
    {
        $fixture = json_encode([
            'elements' => [
                [
                    'type' => 'relation',
                    'id' => 701,
                    'tags' => ['route' => 'subway', 'ref' => 'U1'],
                    'members' => [
                        ['type' => 'node', 'role' => 'stop', 'ref' => 1],
                        [
                            'type' => 'way',
                            'role' => 'platform_entry_only',
                            'geometry' => [['lat' => 48.0, 'lon' => 7.0], ['lat' => 48.01, 'lon' => 7.01]],
                        ],
                        [
                            'type' => 'way',
                            'role' => '',
                            'geometry' => [['lat' => 48.1, 'lon' => 7.1], ['lat' => 48.2, 'lon' => 7.2]],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $geometry = $this->onlyPreviewFeature(
            $this->serviceReturning($fixture)->previewGeometry(['701']),
        )['geometry'];

        self::assertIsArray($geometry);
        self::assertIsArray($geometry['coordinates']);
        self::assertCount(1, $geometry['coordinates']);
    }

    #[Test]
    public function itGivesAPreviewLineAFallbackColourWhenTheRelationHasNone(): void
    {
        $fixture = json_encode([
            'elements' => [
                [
                    'type' => 'relation',
                    'id' => 702,
                    'tags' => ['route' => 'bus', 'ref' => '12'],
                    'members' => [
                        [
                            'type' => 'way',
                            'role' => '',
                            'geometry' => [['lat' => 48.1, 'lon' => 7.1], ['lat' => 48.2, 'lon' => 7.2]],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $properties = $this->onlyPreviewFeature(
            $this->serviceReturning($fixture)->previewGeometry(['702']),
        )['properties'];

        self::assertIsArray($properties);
        $colour = $properties['colour'];
        self::assertIsString($colour);
        self::assertSame(7, strlen($colour));
        self::assertStringStartsWith('#', $colour);
    }

    #[Test]
    public function itDropsAPreviewLineWhoseMembersHaveNoGeometry(): void
    {
        $fixture = json_encode([
            'elements' => [
                [
                    'type' => 'relation',
                    'id' => 703,
                    'tags' => ['route' => 'tram', 'ref' => 'B'],
                    'members' => [['type' => 'way', 'role' => '', 'ref' => 42]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        self::assertNull($this->serviceReturning($fixture)->previewGeometry(['703']));
    }

    #[Test]
    public function itReturnsNoPreviewWithoutUsableIds(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $service = new TransitService(
            new OverpassHttpClient($httpClient, 'https://test.example/api/', false),
            32_000_000,
            self::unusedBoundaryService(),
        );

        self::assertNull($service->previewGeometry(['', 'not-a-number', '0']));
    }

    private function serviceReturning(string $fixture): TransitService
    {
        return new TransitService(
            new OverpassHttpClient(new MockHttpClient(new MockResponse($fixture)), 'https://test.example/api/', false),
            32_000_000,
            self::unusedBoundaryService(),
        );
    }

    /** Previewing works from relation ids alone, so it never resolves an area boundary. */
    private static function unusedBoundaryService(): BoundaryService
    {
        return new class extends BoundaryService {
            public function __construct()
            {
            }
        };
    }

    #[Test]
    public function itPreservesExistingColour(): void
    {
        $fixture = json_encode([
            'elements' => [
                [
                    'type' => 'relation',
                    'id' => 600,
                    'tags' => [
                        'route' => 'subway',
                        'ref' => 'U3',
                        'name' => 'U-Bahn 3',
                        'colour' => '#AA00CC',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($fixture));

        $boundaryService = new class ('48.0,2.0,49.0,3.0') extends BoundaryService {
            public function __construct(private readonly string $bbox)
            {
            }

            public function computeBbox(array $areas): string
            {
                return $this->bbox;
            }
        };

        $service = new TransitService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), 32_000_000, $boundaryService);

        $area = ['osmType' => 'relation', 'osmId' => 123];
        $lines = $service->discoverLines([$area]);

        self::assertCount(1, $lines);
        self::assertSame('#AA00CC', $lines[0]['colour']);
    }
}
