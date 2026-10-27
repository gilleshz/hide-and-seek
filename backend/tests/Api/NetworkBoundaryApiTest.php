<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Repository\FeatureRepository;
use App\Repository\GameAreaRepository;
use App\Repository\GameRepository;
use App\Service\BoundaryService;
use App\Service\NominatimService;
use App\Service\OverpassHttpClient;
use App\Service\OverpassService;
use App\Service\TransitService;
use App\Service\TransitTilePipeline;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NetworkBoundaryApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    private const string AREA_RESPONSE = <<<'JSON'
    [{"osm_type":"relation","osm_id":123,"display_name":"Testville",
      "geojson":{"type":"Polygon","coordinates":[[[6.60,46.50],[6.65,46.50],[6.65,46.55],[6.60,46.55],[6.60,46.50]]]}}]
    JSON;

    private const string REVERSE_RESPONSE = '{"address":{"country_code":"ch"}}';

    private string $geometryResponse;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->geometryResponse = self::ringGeometry();
        $this->replaceServices();
    }

    private function replaceServices(): void
    {
        $container = static::getContainer();

        $nominatim = new NominatimService(
            new MockHttpClient(fn (string $method, string $url): MockResponse => str_contains($url, '/reverse')
                ? new MockResponse(self::REVERSE_RESPONSE)
                : new MockResponse(self::AREA_RESPONSE)),
            new ArrayAdapter(),
            'test-agent',
            'https://nominatim.test',
        );
        $container->set(NominatimService::class, $nominatim);

        $gameAreaRepository = $container->get(GameAreaRepository::class);
        self::assertInstanceOf(GameAreaRepository::class, $gameAreaRepository);
        $boundaryService = new BoundaryService($nominatim, $gameAreaRepository);
        $container->set(BoundaryService::class, $boundaryService);

        $overpassFactory = function (string $method, string $url, array $options = []): MockResponse {
            $body = $options['body'] ?? '';
            $query = '';
            if (is_string($body) && $body !== '') {
                parse_str($body, $parsed);
                if (isset($parsed['data']) && is_string($parsed['data'])) {
                    $query = $parsed['data'];
                }
            }

            return str_contains($query, 'out geom')
                ? new MockResponse($this->geometryResponse)
                : new MockResponse('{"elements":[]}');
        };
        $overpassHttpClient = new OverpassHttpClient(
            new MockHttpClient($overpassFactory),
            'https://test.example/api/',
            false,
        );
        $container->set(TransitService::class, new TransitService(
            $overpassHttpClient,
            32_000_000,
            $boundaryService,
        ));

        /** @var FeatureRepository $features */
        $features = $container->get(FeatureRepository::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $container->set(OverpassService::class, new OverpassService(
            $overpassHttpClient,
            $features,
            $entityManager,
        ));

        $tilePipeline = $this->createStub(TransitTilePipeline::class);
        $tilePipeline->method('build')->willReturn(1);
        $container->set(TransitTilePipeline::class, $tilePipeline);
    }

    #[Test]
    public function itPreviewsARoundedHullFromLinesAndCoversTheZoneAroundItsStops(): void
    {
        $client = static::createClient();
        $response = $client->request('POST', '/api/boundary-preview', self::AUTH + [
            'json' => ['osmLineIds' => ['100'], 'size' => 'M', 'edition' => 'metric'],
        ]);

        self::assertResponseStatusCodeSame(201);
        /** @var array<string, mixed> $body */
        $body = $response->toArray();
        self::assertIsString($body['geoJson'] ?? null);

        $stats = $this->boundaryStats($body['geoJson'], 6.65 + 0.05 / cos(deg2rad(46.55)), 46.55);
        self::assertTrue($stats['covers'], 'the 1125 m zone around a ring stop must be inside the boundary');
        self::assertGreaterThan(6.735, $stats['east']);
        self::assertLessThan(6.565, $stats['west']);
        self::assertGreaterThan(46.60, $stats['north']);
        self::assertLessThan(46.50, $stats['south']);
    }

    #[Test]
    public function itFallsBackToAPlainBufferForALineShapedNetwork(): void
    {
        $this->geometryResponse = '{"elements":[{"type":"relation","id":100,"members":['
            . '{"type":"way","role":"","geometry":[{"lat":46.50,"lon":6.60},{"lat":46.55,"lon":6.65}]}]}]}';

        $client = static::createClient();
        $response = $client->request('POST', '/api/boundary-preview', self::AUTH + [
            'json' => ['osmLineIds' => ['100'], 'size' => 'M', 'edition' => 'metric'],
        ]);

        self::assertResponseStatusCodeSame(201);
        /** @var array<string, mixed> $body */
        $body = $response->toArray();
        self::assertIsString($body['geoJson'] ?? null);

        $stats = $this->boundaryStats($body['geoJson'], 6.60, 46.50);
        self::assertTrue($stats['covers']);
        self::assertLessThan(6.60, $stats['west']);
        self::assertGreaterThan(6.65, $stats['east']);
        self::assertLessThan(46.50, $stats['south']);
        self::assertGreaterThan(46.55, $stats['north']);
    }

    #[Test]
    public function itKeepsTheSelectedAreasWhenBothAreGiven(): void
    {
        $client = static::createClient();
        $response = $client->request('POST', '/api/boundary-preview', self::AUTH + [
            'json' => [
                'areas' => [['osmType' => 'relation', 'osmId' => 123]],
                'osmLineIds' => ['100'],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        /** @var array<string, mixed> $body */
        $body = $response->toArray();
        self::assertIsString($body['geoJson'] ?? null);

        $stats = $this->boundaryStats($body['geoJson'], 6.625, 46.525);
        self::assertEqualsWithDelta(6.60, $stats['west'], 0.0001);
        self::assertEqualsWithDelta(6.65, $stats['east'], 0.0001);
        self::assertEqualsWithDelta(46.50, $stats['south'], 0.0001);
        self::assertEqualsWithDelta(46.55, $stats['north'], 0.0001);
    }

    #[Test]
    public function itRejectsAPreviewWithoutAreasOrLines(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/boundary-preview', self::AUTH + ['json' => []]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'area_fit.no_lines']);
    }

    #[Test]
    public function itRejectsALineBoundaryForALargeGame(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/boundary-preview', self::AUTH + [
            'json' => ['osmLineIds' => ['100'], 'size' => 'L', 'edition' => 'metric'],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'game.boundary_areas_required']);
    }

    #[Test]
    public function itRejectsALineNetworkOverTheSpanCap(): void
    {
        $this->geometryResponse = '{"elements":[{"type":"relation","id":100,"members":['
            . '{"type":"way","role":"","geometry":[{"lat":0,"lon":0},{"lat":10,"lon":10}]}]}]}';

        $client = static::createClient();
        $client->request('POST', '/api/boundary-preview', self::AUTH + [
            'json' => ['osmLineIds' => ['100'], 'size' => 'M', 'edition' => 'metric'],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'area_fit.too_large']);
    }

    #[Test]
    public function itRejectsALineBoundaryWhoseBufferPushesItOverTheSpanCap(): void
    {
        $this->geometryResponse = '{"elements":[{"type":"relation","id":100,"members":['
            . '{"type":"way","role":"","geometry":[{"lat":45.0,"lon":6.6},{"lat":50.99,"lon":6.6}]}]}]}';

        $client = static::createClient();
        $client->request('POST', '/api/boundary-preview', self::AUTH + [
            'json' => ['osmLineIds' => ['100'], 'size' => 'M', 'edition' => 'metric'],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'area_fit.too_large']);
    }

    #[Test]
    public function itCreatesAGameWithALineDerivedBoundaryAndNoGameAreas(): void
    {
        $client = static::createClient();
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => [
                'name' => 'Line Boundary',
                'size' => 'M',
                'edition' => 'metric',
                'selectedTransitLines' => [self::lineRef()],
            ],
        ])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($game['boundaryGeoJson'] ?? null);
        self::assertNotNull($game['boundarySwLat'] ?? null);
        self::assertGreaterThan(6.735, $game['boundaryNeLng'] ?? 0.0);

        $uuid = $game['uuid'] ?? '';
        self::assertIsString($uuid);
        /** @var GameRepository $games */
        $games = static::getContainer()->get(GameRepository::class);
        $entity = $games->findOneByUuid($uuid);
        self::assertNotNull($entity);
        /** @var GameAreaRepository $gameAreas */
        $gameAreas = static::getContainer()->get(GameAreaRepository::class);
        self::assertCount(0, $gameAreas->findByGame($entity));
    }

    #[Test]
    public function itKeepsAreasWhenACreateSendsBothAreasAndLines(): void
    {
        $client = static::createClient();
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => [
                'name' => 'Area Boundary',
                'size' => 'M',
                'edition' => 'metric',
                'areas' => [['osmType' => 'relation', 'osmId' => 123]],
                'selectedTransitLines' => [self::lineRef()],
            ],
        ])->toArray();

        self::assertResponseStatusCodeSame(201);
        $west = $game['boundarySwLng'] ?? null;
        $east = $game['boundaryNeLng'] ?? null;
        self::assertIsNumeric($west);
        self::assertIsNumeric($east);
        self::assertEqualsWithDelta(6.60, (float) $west, 0.0001);
        self::assertEqualsWithDelta(6.65, (float) $east, 0.0001);

        $uuid = $game['uuid'] ?? '';
        self::assertIsString($uuid);
        /** @var GameRepository $games */
        $games = static::getContainer()->get(GameRepository::class);
        $entity = $games->findOneByUuid($uuid);
        self::assertNotNull($entity);
        /** @var GameAreaRepository $gameAreas */
        $gameAreas = static::getContainer()->get(GameAreaRepository::class);
        self::assertCount(1, $gameAreas->findByGame($entity));
    }

    #[Test]
    public function itRejectsAZoneOutsideTheBoundary(): void
    {
        $client = static::createClient();
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => [
                'name' => 'Zone Boundary',
                'size' => 'M',
                'edition' => 'metric',
                'areas' => [['osmType' => 'relation', 'osmId' => 123]],
            ],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);

        $session = $this->joinAndPickSide($client, (string) $game['uuid'], (string) $game['roundUuid'], 'Alice', 'hider');

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/zone", $this->headersWithToken($session['token']) + [
            'json' => ['lat' => 46.525, 'lng' => 6.625],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/zone", $this->headersWithToken($session['token']) + [
            'json' => ['lat' => 46.60, 'lng' => 6.70],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'hiding_zone.outside_boundary']);
    }

    /** @return array{west: float, east: float, south: float, north: float, covers: bool} */
    private function boundaryStats(string $geoJson, float $lng, float $lat): array
    {
        $decoded = json_decode($geoJson, true, 512, JSON_THROW_ON_ERROR);
        $features = is_array($decoded) ? ($decoded['features'] ?? null) : null;
        $geometry = is_array($features) && is_array($features[0] ?? null) && isset($features[0]['geometry'])
            ? $features[0]['geometry']
            : $decoded;
        $geometryJson = json_encode($geometry, JSON_THROW_ON_ERROR);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $row = $entityManager->getConnection()->fetchAssociative(<<<'SQL'
            WITH boundary AS (SELECT ST_GeomFromGeoJSON(:geoJson) AS geom)
            SELECT ST_XMin(Box3D(geom)) AS west, ST_XMax(Box3D(geom)) AS east,
                   ST_YMin(Box3D(geom)) AS south, ST_YMax(Box3D(geom)) AS north,
                   ST_Covers(
                       geom,
                       ST_Buffer(ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, 1125)::geometry
                   )::int AS covers
            FROM boundary
        SQL, ['geoJson' => $geometryJson, 'lng' => $lng, 'lat' => $lat]);

        self::assertIsArray($row);
        self::assertIsNumeric($row['west'] ?? null);
        self::assertIsNumeric($row['east'] ?? null);
        self::assertIsNumeric($row['south'] ?? null);
        self::assertIsNumeric($row['north'] ?? null);
        self::assertIsNumeric($row['covers'] ?? null);

        return [
            'west' => (float) $row['west'],
            'east' => (float) $row['east'],
            'south' => (float) $row['south'],
            'north' => (float) $row['north'],
            'covers' => (int) $row['covers'] === 1,
        ];
    }

    /** @return array<string, mixed> */
    private static function lineRef(): array
    {
        return [
            'osmType' => 'relation',
            'osmId' => 100,
            'ref' => 'A',
            'name' => 'Line A',
            'colour' => '#FF0000',
            'routeType' => 'subway',
            'network' => 'Metro',
            'operator' => 'TransitCo',
        ];
    }

    private static function ringGeometry(): string
    {
        $members = [];
        $latCenter = 46.55;
        $lngCenter = 6.65;
        $radius = 0.05;
        for ($i = 0; $i < 12; ++$i) {
            $angle = 2 * M_PI * $i / 12;
            $members[] = [
                'type' => 'way',
                'role' => '',
                'geometry' => [
                    ['lat' => $latCenter, 'lon' => $lngCenter],
                    [
                        'lat' => round($latCenter + $radius * sin($angle), 6),
                        'lon' => round($lngCenter + $radius * cos($angle) / cos(deg2rad($latCenter)), 6),
                    ],
                ],
            ];
        }

        return json_encode(['elements' => [[
            'type' => 'relation',
            'id' => 100,
            'members' => $members,
        ]]], JSON_THROW_ON_ERROR);
    }
}
