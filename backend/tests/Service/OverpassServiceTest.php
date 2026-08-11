<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Enum\Edition;
use App\Enum\FeatureType;
use App\Enum\GameSize;
use App\Repository\FeatureRepository;
use App\Service\OverpassHttpClient;
use App\Service\OverpassService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(OverpassService::class)]
final class OverpassServiceTest extends TestCase
{
    private const string OVERPASS_RESPONSE = <<<'JSON'
{
    "version": 0.6,
    "elements": [
        {"type": "node", "id": 1, "lat": 48.85, "lon": 2.35, "tags": {"amenity": "hospital", "name": "Hospital A"}},
        {"type": "node", "id": 2, "lat": 48.86, "lon": 2.36, "tags": {"tourism": "museum", "name": "Museum B"}},
        {"type": "node", "id": 3, "lat": 48.87, "lon": 2.37, "tags": {"leisure": "park", "name": "Park C"}},
        {"type": "node", "id": 4, "lat": 48.88, "lon": 2.38, "tags": {"natural": "peak", "name": "Mont D", "ele": "1000"}},
        {"type": "node", "id": 5, "lat": 48.89, "lon": 2.39, "tags": {"railway": "station", "name": "Gare E"}},
        {"type": "node", "id": 6, "lat": 48.90, "lon": 2.40, "tags": {"amenity": "cinema", "name": "Cinema P"}},
        {"type": "node", "id": 7, "lat": 48.91, "lon": 2.41, "tags": {"amenity": "library", "name": "Library Q"}},
        {"type": "node", "id": 8, "lat": 48.92, "lon": 2.42, "tags": {"amenity": "golf_course", "name": "Golf Club F"}},
        {"type": "node", "id": 9, "lat": 48.93, "lon": 2.43, "tags": {"tourism": "zoo", "name": "Zoo R"}},
        {"type": "node", "id": 10, "lat": 48.94, "lon": 2.44, "tags": {"natural": "water", "name": "Lake G"}},
        {"type": "node", "id": 11, "lat": 48.95, "lon": 2.45, "tags": {"amenity": "embassy", "name": "Embassy H"}},
        {"type": "node", "id": 12, "lat": 48.96, "lon": 2.46, "tags": {"amenity": "theatre", "name": "Opera W"}},
        {"type": "node", "id": 13, "lat": 48.97, "lon": 2.47, "tags": {"public_transport": "station", "name": "Station S"}},
        {"type": "node", "id": 14, "lat": 48.98, "lon": 2.48, "tags": {"tourism": "aquarium", "name": "Aquarium I"}},
        {"type": "node", "id": 15, "lat": 48.99, "lon": 2.49, "tags": {"tourism": "theme_park", "name": "Park J"}},
        {"type": "node", "id": 16, "lat": 49.00, "lon": 2.50, "tags": {"leisure": "golf_course", "name": "Golf T"}},
        {"type": "node", "id": 17, "lat": 49.01, "lon": 2.51, "tags": {"aeroway": "aerodrome", "name": "Airport K"}},
        {"type": "node", "id": 18, "lat": 49.02, "lon": 2.52, "tags": {"tourism": "theme_park", "name": "Park U"}},
        {"type": "node", "id": 19, "lat": 49.03, "lon": 2.53, "tags": {"natural": "coastline"}},
        {"type": "node", "id": 20, "lat": 49.04, "lon": 2.54, "tags": {"amenity": "place_of_worship", "name": "Chapel L"}},
        {"type": "node", "id": 21, "lat": 49.05, "lon": 2.55, "tags": {"boundary": "administrative", "name": "Region M"}},
        {"type": "node", "id": 22, "lat": 49.06, "lon": 2.56, "tags": {"shop": "supermarket", "name": "Shop N"}},
        {"type": "node", "id": 23, "lat": 49.07, "lon": 2.57, "tags": {"office": "diplomatic", "name": "Consulate X"}},
        {"type": "node", "id": 24, "lat": 49.08, "lon": 2.58, "tags": {"waterway": "river", "name": "Seine"}},
        {"type": "node", "id": 25, "lat": 49.09, "lon": 2.59, "tags": {"waterway": "canal", "name": "Canal Y"}}
    ]
}
JSON;

    #[Test]
    public function itIngestsFeaturesAndMapsTagsCorrectly(): void
    {
        $game = $this->createGameWithBoundary();
        $response = new MockResponse(self::OVERPASS_RESPONSE);
        $httpClient = new MockHttpClient($response);

        $features = $this->createMock(FeatureRepository::class);
        $expectedSaves = 20;
        $features->expects(self::exactly($expectedSaves))->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em);
        $count = $service->ingestFeatures($game);

        self::assertSame($expectedSaves, $count);
    }

    #[Test]
    public function itIngestsZeroFeaturesWhenNoElementsAreReturned(): void
    {
        $game = $this->createGameWithBoundary();
        $response = new MockResponse('{"version": 0.6, "elements": []}');
        $httpClient = new MockHttpClient($response);

        $features = $this->createMock(FeatureRepository::class);
        $features->expects(self::never())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em);
        $count = $service->ingestFeatures($game);

        self::assertSame(0, $count);
    }

    #[Test]
    public function itThrowsWhenGameHasNoBoundary(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);

        $features = $this->createStub(FeatureRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Game has no boundary');

        $response = new MockResponse('{}');
        $httpClient = new MockHttpClient($response);

        new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em)
            ->ingestFeatures($game);
    }

    #[Test]
    public function itMapsTagsToTheCorrectFeatureType(): void
    {
        $game = $this->createGameWithBoundary();
        $response = new MockResponse(self::OVERPASS_RESPONSE);
        $httpClient = new MockHttpClient($response);

        $captured = [];
        $features = $this->createStub(FeatureRepository::class);
        $features->method('save')->willReturnCallback(
            function ($entity) use (&$captured): void {
                $captured[] = $entity;
            },
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush');

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em);
        $service->ingestFeatures($game);

        $expectedTypes = [
            ['Hospital A', FeatureType::Hospital],
            ['Museum B', FeatureType::Museum],
            ['Park C', FeatureType::Park],
            ['Mont D', FeatureType::Mountain],
            ['Gare E', FeatureType::RailStation],
            ['Cinema P', FeatureType::MovieTheater],
            ['Library Q', FeatureType::Library],
            ['Zoo R', FeatureType::Zoo],
            ['Lake G', FeatureType::BodyOfWater],
            ['Embassy H', FeatureType::Consulate],
            ['Station S', FeatureType::TransitStation],
            ['Aquarium I', FeatureType::Aquarium],
            ['Park J', FeatureType::AmusementPark],
            ['Golf T', FeatureType::GolfCourse],
            ['Airport K', FeatureType::CommercialAirport],
            ['Park U', FeatureType::AmusementPark],
            [null, FeatureType::Coastline],
            ['Consulate X', FeatureType::Consulate],
            ['Seine', FeatureType::BodyOfWater],
            ['Canal Y', FeatureType::BodyOfWater],
        ];

        self::assertCount(count($expectedTypes), $captured);

        $idx = 0;
        foreach ($expectedTypes as [$expectedName, $expectedType]) {
            $feature = $captured[$idx];
            self::assertInstanceOf(\App\Entity\Feature::class, $feature);
            self::assertSame($expectedType, $feature->getFeatureType(), "Item {$idx}: wrong type");
            self::assertSame($expectedName, $feature->getName(), "Item {$idx}: wrong name");
            ++$idx;
        }
    }

    #[Test]
    public function itIngestsCommercialAirportsMappedAsWays(): void
    {
        $game = $this->createGameWithBoundary();
        $json = <<<'JSON'
{
    "version": 0.6,
    "elements": [
        {"type": "way", "id": 100, "tags": {"aeroway": "aerodrome", "iata": "SXB", "name": "Entzheim"}, "geometry": [
            {"lat": 48.53, "lon": 7.62},
            {"lat": 48.53, "lon": 7.63},
            {"lat": 48.54, "lon": 7.63},
            {"lat": 48.54, "lon": 7.62},
            {"lat": 48.53, "lon": 7.62}
        ]}
    ]
}
JSON;
        $response = new MockResponse($json);
        $httpClient = new MockHttpClient($response);

        $captured = [];
        $features = $this->createStub(FeatureRepository::class);
        $features->method('save')->willReturnCallback(
            function ($entity) use (&$captured): void {
                $captured[] = $entity;
            },
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush');

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em);
        $count = $service->ingestFeatures($game);

        self::assertSame(1, $count);
        $feature = $captured[0];
        self::assertInstanceOf(\App\Entity\Feature::class, $feature);
        self::assertSame(FeatureType::CommercialAirport, $feature->getFeatureType());
        self::assertStringStartsWith('POLYGON((', (string) $feature->getGeometry());
    }

    #[Test]
    public function itFiltersAirportsToThoseWithAnIataCode(): void
    {
        $game = $this->createGameWithBoundary();
        $capturedBody = '';
        $httpClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
                $body = $options['body'] ?? null;
                $capturedBody = is_string($body) ? $body : '';

                return new MockResponse('{"version": 0.6, "elements": []}');
            },
        );

        $features = $this->createStub(FeatureRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);

        new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em)
            ->ingestFeatures($game);

        self::assertStringContainsString('aeroway', $capturedBody);
        self::assertStringContainsString('iata', $capturedBody);
    }

    #[Test]
    public function itQueriesHighSpeedRailLinesByTheHighspeedTag(): void
    {
        $game = $this->createGameWithBoundary();
        $capturedBody = '';
        $httpClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
                $body = $options['body'] ?? null;
                $capturedBody = is_string($body) ? $body : '';

                return new MockResponse('{"version": 0.6, "elements": []}');
            },
        );

        $features = $this->createStub(FeatureRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);

        new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em)
            ->ingestFeatures($game);

        self::assertStringContainsString('highspeed', $capturedBody);
    }

    #[Test]
    public function itSkipsElementsWithoutTags(): void
    {
        $game = $this->createGameWithBoundary();
        $json = '{"version": 0.6, "elements": [{"type": "node", "id": 1, "lat": 48.0, "lon": 2.0}, {"type": "node", "id": 2, "lat": 48.0, "lon": 2.0, "tags": {"amenity": "hospital", "name": "Test"}}]}';
        $response = new MockResponse($json);
        $httpClient = new MockHttpClient($response);

        $captured = [];
        $features = $this->createStub(FeatureRepository::class);
        $features->method('save')->willReturnCallback(
            function ($entity) use (&$captured): void {
                $captured[] = $entity;
            },
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush');

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em);
        $count = $service->ingestFeatures($game);

        self::assertSame(1, $count);
        self::assertCount(1, $captured);
    }

    #[Test]
    public function itRetriesOnServerErrorAndRotatesMirrors(): void
    {
        $game = $this->createGameWithBoundary();

        $errorResponse = new MockResponse('', ['http_code' => 504]);
        $successResponse = new MockResponse(self::OVERPASS_RESPONSE);
        $httpClient = new MockHttpClient([$errorResponse, $successResponse]);

        $features = $this->createMock(FeatureRepository::class);
        $features->expects(self::exactly(20))->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'http://mirror-a/api,http://mirror-b/api', false), $features, $em);
        $count = $service->ingestFeatures($game);

        self::assertSame(20, $count);
    }

    #[Test]
    public function itIngestsAdminDivisionsViaTheDynamicAdminLevelQuery(): void
    {
        $game = $this->createGameWithBoundary();
        $game->setAdminLevels([1 => 4]);

        $json = <<<'JSON'
{
    "version": 0.6,
    "elements": [
        {"type": "relation", "id": 500, "tags": {"boundary": "administrative", "name": "Region M"}, "members": [
            {"type": "node", "ref": 1, "role": "admin_centre", "lat": 48.15, "lon": 2.15},
            {"type": "way", "ref": 10, "role": "outer", "geometry": [
                {"lat": 48.10, "lon": 2.10},
                {"lat": 48.10, "lon": 2.20},
                {"lat": 48.20, "lon": 2.20}
            ]},
            {"type": "way", "ref": 11, "role": "outer", "geometry": [
                {"lat": 48.20, "lon": 2.20},
                {"lat": 48.20, "lon": 2.10},
                {"lat": 48.10, "lon": 2.10}
            ]}
        ]}
    ]
}
JSON;

        $capturedBody = '';
        $httpClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedBody, $json): MockResponse {
                $body = $options['body'] ?? null;
                $capturedBody = is_string($body) ? $body : '';

                return new MockResponse($json);
            },
        );

        $capturedMls = '';
        $features = $this->createMock(FeatureRepository::class);
        $features->method('countByGameAndType')->willReturn(0);
        $features->expects(self::once())
            ->method('insertAssembledAdminDivision')
            ->with(
                self::identicalTo($game),
                self::identicalTo(FeatureType::AdminBoundary1st),
                self::identicalTo('Region M'),
                self::stringContains('MULTILINESTRING('),
            )
            ->willReturnCallback(
                function ($g, $t, $n, string $mls) use (&$capturedMls): bool {
                    $capturedMls = $mls;

                    return true;
                },
            );
        $features->expects(self::never())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em);
        $count = $service->ingestFeatureType($game, FeatureType::AdminBoundary1st);

        self::assertSame(1, $count);
        self::assertStringContainsString('["admin_level"="4"]', urldecode($capturedBody));
        self::assertStringContainsString('2.1 48.1', $capturedMls);
        self::assertStringContainsString('2.2 48.2', $capturedMls);
    }

    #[Test]
    public function itIngestsNoAdminDivisionWhenNoLevelIsResolved(): void
    {
        $game = $this->createGameWithBoundary();

        $httpClient = new MockHttpClient(new MockResponse('{"version": 0.6, "elements": []}'));
        $features = $this->createMock(FeatureRepository::class);
        $features->expects(self::never())->method('save');

        $em = $this->createStub(EntityManagerInterface::class);

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em);
        $count = $service->ingestFeatureType($game, FeatureType::AdminBoundary1st);

        self::assertSame(0, $count);
    }

    #[Test]
    public function itIngestsAdminBordersAsLinestringsViaMemberWays(): void
    {
        $game = $this->createGameWithBoundary();
        $game->setAdminLevels([1 => 4]);

        $json = <<<'JSON'
{
    "version": 0.6,
    "elements": [
        {"type": "way", "id": 600, "tags": {"boundary": "administrative", "name": "Border Way"}, "geometry": [
            {"lat": 48.10, "lon": 2.10},
            {"lat": 48.20, "lon": 2.20},
            {"lat": 48.30, "lon": 2.30}
        ]}
    ]
}
JSON;

        $capturedBody = '';
        $httpClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedBody, $json): MockResponse {
                $body = $options['body'] ?? null;
                $capturedBody = is_string($body) ? $body : '';

                return new MockResponse($json);
            },
        );

        $captured = [];
        $features = $this->createStub(FeatureRepository::class);
        $features->method('countByGameAndType')->willReturn(0);
        $features->method('save')->willReturnCallback(
            function ($entity) use (&$captured): void {
                $captured[] = $entity;
            },
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush');

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em);
        $count = $service->ingestFeatureType($game, FeatureType::Border1st);

        self::assertSame(1, $count);
        $decoded = urldecode($capturedBody);
        self::assertStringContainsString('["admin_level"="4"]', $decoded);
        self::assertStringContainsString('way(r)(', $decoded);
        $feature = $captured[0];
        self::assertInstanceOf(\App\Entity\Feature::class, $feature);
        self::assertSame(FeatureType::Border1st, $feature->getFeatureType());
        self::assertStringStartsWith('LINESTRING(', (string) $feature->getGeometry());
    }

    #[Test]
    public function itQueriesTheInternationalBorderAtAdminLevelTwo(): void
    {
        $game = $this->createGameWithBoundary();

        $capturedBody = '';
        $httpClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
                $body = $options['body'] ?? null;
                $capturedBody = is_string($body) ? $body : '';

                return new MockResponse('{"version": 0.6, "elements": []}');
            },
        );

        $features = $this->createStub(FeatureRepository::class);
        $features->method('countByGameAndType')->willReturn(0);

        $em = $this->createStub(EntityManagerInterface::class);

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em);
        $service->ingestFeatureType($game, FeatureType::BorderInternational);

        self::assertStringContainsString('["admin_level"="2"]', urldecode($capturedBody));
    }

    #[Test]
    public function itIngestsNoBorderWhenNoLevelIsResolved(): void
    {
        $game = $this->createGameWithBoundary();

        $httpClient = new MockHttpClient(new MockResponse('{"version": 0.6, "elements": []}'));
        $features = $this->createMock(FeatureRepository::class);
        $features->expects(self::never())->method('save');

        $em = $this->createStub(EntityManagerInterface::class);

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em);
        $count = $service->ingestFeatureType($game, FeatureType::Border1st);

        self::assertSame(0, $count);
    }

    #[Test]
    public function itLocatesAreaMappedPoisAtTheirBoundsCenter(): void
    {
        $game = $this->createGameWithBoundary();
        $json = <<<'JSON'
{
    "version": 0.6,
    "elements": [
        {"type": "way", "id": 700, "bounds": {"minlat": 48.60, "minlon": 2.30, "maxlat": 48.80, "maxlon": 2.50}, "tags": {"amenity": "hospital", "name": "CHR Metz"}, "geometry": [
            {"lat": 48.60, "lon": 2.30},
            {"lat": 48.60, "lon": 2.50},
            {"lat": 48.80, "lon": 2.50},
            {"lat": 48.80, "lon": 2.30},
            {"lat": 48.60, "lon": 2.30}
        ]}
    ]
}
JSON;
        $response = new MockResponse($json);
        $httpClient = new MockHttpClient($response);

        $captured = [];
        $features = $this->createStub(FeatureRepository::class);
        $features->method('save')->willReturnCallback(
            function ($entity) use (&$captured): void {
                $captured[] = $entity;
            },
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush');

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em);
        $count = $service->ingestFeatures($game);

        self::assertSame(1, $count);
        $feature = $captured[0];
        self::assertInstanceOf(\App\Entity\Feature::class, $feature);
        self::assertSame(FeatureType::Hospital, $feature->getFeatureType());
        self::assertNull($feature->getGeometry());
        self::assertSame(48.70, $feature->getPoint()->getLatitude());
        self::assertSame(2.40, $feature->getPoint()->getLongitude());
    }

    #[Test]
    public function itQueriesAreaMappedPoisAsNodesWaysAndRelations(): void
    {
        $game = $this->createGameWithBoundary();

        $capturedBody = '';
        $httpClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
                $body = $options['body'] ?? null;
                $capturedBody = is_string($body) ? $body : '';

                return new MockResponse('{"version": 0.6, "elements": []}');
            },
        );

        $features = $this->createStub(FeatureRepository::class);
        $features->method('countByGameAndType')->willReturn(0);

        $em = $this->createStub(EntityManagerInterface::class);

        $service = new OverpassService(new OverpassHttpClient($httpClient, 'https://test.example/api/', false), $features, $em);
        $service->ingestFeatureType($game, FeatureType::GolfCourse);

        $decoded = urldecode($capturedBody);
        self::assertStringContainsString('node["leisure"="golf_course"]', $decoded);
        self::assertStringContainsString('way["leisure"="golf_course"]', $decoded);
        self::assertStringContainsString('relation["leisure"="golf_course"]', $decoded);
        self::assertStringNotContainsString('"amenity"="golf_course"', $decoded);
    }

    private function createGameWithBoundary(): Game
    {
        $game = new Game('Paris', GameSize::Medium, Edition::Metric);
        $game->setBoundary(48.0, 2.0, 50.0, 4.0);

        return $game;
    }
}
