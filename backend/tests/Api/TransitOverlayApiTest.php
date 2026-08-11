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

final class TransitOverlayApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;


    private const string NOMINATIM_RESPONSE = <<<'JSON'
    [{"osm_type":"relation","osm_id":123,"display_name":"Testville",
      "geojson":{"type":"Polygon","coordinates":[[[6.60,46.50],[6.65,46.50],[6.65,46.55],[6.60,46.55],[6.60,46.50]]]}}]
    JSON;

    private const string OVERPASS_EMPTY = '{"elements":[]}';

    /** @return array{json: array<string, mixed>} */
    private static function transitGame(string $name): array
    {
        return ['json' => [
            'name' => $name,
            'size' => 'M',
            'edition' => 'metric',
            'areas' => [['osmType' => 'relation', 'osmId' => 123]],
            'selectedTransitLines' => [[
                'osmType' => 'relation',
                'osmId' => 100,
                'ref' => 'A',
                'name' => 'Line A',
                'colour' => '#FF0000',
                'routeType' => 'subway',
                'network' => 'Metro',
                'operator' => 'TransitCo',
            ]],
        ]];
    }

    private function gamesNamed(string $name): int
    {
        $games = static::getContainer()->get(GameRepository::class);
        self::assertInstanceOf(GameRepository::class, $games);

        return count($games->findBy(['name' => $name]));
    }

    private const string DISCOVERY_RESPONSE = <<<'JSON'
    {"elements":[
      {
        "type":"relation","id":100,
        "tags":{"route":"subway","ref":"A","name":"Line A","colour":"#FF0000","network":"Metro","operator":"TransitCo"}
      },
      {
        "type":"relation","id":101,
        "tags":{"route":"tram","ref":"B","name":"Line B","colour":"#0000FF","network":"CityTram","operator":"TransitCo"}
      }
    ]}
    JSON;

    private const string GEOMETRY_RESPONSE = <<<'JSON'
    {"elements":[
      {"type":"relation","id":100,"tags":{"route":"subway","ref":"A","name":"Line A","colour":"#FF0000"},
       "members":[{"type":"way","geometry":[{"lat":46.51,"lon":6.61},{"lat":46.52,"lon":6.62}]}]}
    ]}
    JSON;

    private int $tilesBuilt = 1;

    private ?\Throwable $tileBuildFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->replaceServices();
    }

    private function replaceServices(): void
    {
        $container = static::getContainer();

        $nominatimFactory = function (string $method, string $url): MockResponse {
            if (str_contains($url, '/reverse')) {
                return new MockResponse('{"address":{"country_code":"ch"}}');
            }

            return new MockResponse(self::NOMINATIM_RESPONSE);
        };
        $nominatim = new NominatimService(
            new MockHttpClient($nominatimFactory),
            new ArrayAdapter(),
            'test-agent',
            'https://nominatim.test',
        );
        $container->set(NominatimService::class, $nominatim);

        $gameAreaRepository = $container->get(GameAreaRepository::class);
        self::assertInstanceOf(GameAreaRepository::class, $gameAreaRepository);
        $boundaryService = new BoundaryService($nominatim, $gameAreaRepository);
        $container->set(BoundaryService::class, $boundaryService);

        $overpassResponseFactory = function (string $method, string $url, array $options = []): MockResponse {
            $body = $options['body'] ?? '';
            $query = '';

            if (is_string($body) && $body !== '') {
                parse_str($body, $parsed);
                if (isset($parsed['data']) && is_string($parsed['data'])) {
                    $query = $parsed['data'];
                }
            }

            if (str_contains($query, 'out geom')) {
                return new MockResponse(self::GEOMETRY_RESPONSE);
            }
            if (str_contains($query, 'out tags')) {
                return new MockResponse(self::DISCOVERY_RESPONSE);
            }

            return new MockResponse(self::OVERPASS_EMPTY);
        };

        $transit = new TransitService(
            new OverpassHttpClient(new MockHttpClient($overpassResponseFactory), 'https://test.example/api/', false),
            32_000_000,
            $boundaryService,
        );
        $container->set(TransitService::class, $transit);

        /** @var FeatureRepository $features */
        $features = $container->get(FeatureRepository::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $overpass = new OverpassService(
            new OverpassHttpClient(new MockHttpClient([new MockResponse(self::OVERPASS_EMPTY)]), 'https://test.example/api/', false),
            $features,
            $em,
        );
        $container->set(OverpassService::class, $overpass);

        $tilePipeline = $this->createStub(TransitTilePipeline::class);
        $tilePipeline->method('build')->willReturnCallback(function (): int {
            if ($this->tileBuildFailure !== null) {
                throw $this->tileBuildFailure;
            }

            return $this->tilesBuilt;
        });
        $container->set(TransitTilePipeline::class, $tilePipeline);
    }

    #[Test]
    public function boundaryPreviewReturnsFeatureCollection(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/boundary-preview', self::AUTH + [
            'json' => ['areas' => [['osmType' => 'relation', 'osmId' => 123]]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey('geoJson', $data);
        self::assertIsString($data['geoJson']);
        $geoJson = json_decode($data['geoJson'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($geoJson);
        self::assertArrayHasKey('type', $geoJson);
        self::assertSame('FeatureCollection', $geoJson['type']);
        self::assertArrayHasKey('features', $geoJson);
        self::assertIsArray($geoJson['features']);
        self::assertGreaterThanOrEqual(1, count($geoJson['features']));
    }

    #[Test]
    public function transitLinesDiscoveryReturnsLineObjects(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/transit-lines', self::AUTH + [
            'json' => ['areas' => [['osmType' => 'relation', 'osmId' => 123]]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertCount(2, $data);
        self::assertIsArray($data[0]);
        self::assertArrayHasKey('osmId', $data[0]);
        self::assertSame('100', $data[0]['osmId']);
        self::assertSame('subway', $data[0]['routeType']);
        self::assertSame('#FF0000', $data[0]['colour']);
        self::assertIsArray($data[1]);
        self::assertSame('101', $data[1]['osmId']);
        self::assertSame('tram', $data[1]['routeType']);
    }

    #[Test]
    public function gameCreationWithSelectedTransitLinesStoresTheTilePathAndNoGeoJson(): void
    {
        $client = static::createClient();

        $created = $client->request('POST', '/api/games', self::AUTH + self::transitGame('Tiles Built'))->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Tiles Built', $created['name']);
        $uuid = $created['uuid'];
        self::assertIsString($uuid);
        self::assertNotEmpty($uuid);
        self::assertIsString($created['transitTilesPath']);
        self::assertStringEndsWith($uuid, $created['transitTilesPath']);
        self::assertArrayNotHasKey('transitOverlay', $created);
    }

    #[Test]
    public function gameCreationFailsWhenTheTilePipelineBuildsNothing(): void
    {
        $this->tilesBuilt = 0;

        $client = static::createClient();
        $client->request('POST', '/api/games', self::AUTH + self::transitGame('No Tiles'));

        self::assertResponseStatusCodeSame(400);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $data = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('Could not generate the transit map for the selected lines.', $data['detail'] ?? null);
        self::assertSame(0, $this->gamesNamed('No Tiles'));
    }

    #[Test]
    public function gameCreationFailsWhenTheTilePipelineThrows(): void
    {
        $this->tileBuildFailure = new \RuntimeException('loom exited 1');

        $client = static::createClient();
        $client->request('POST', '/api/games', self::AUTH + self::transitGame('Pipeline Crash'));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->gamesNamed('Pipeline Crash'));
    }

    #[Test]
    public function boundaryPreviewFailsWithoutApiKey(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/boundary-preview', [
            'json' => ['areas' => [['osmType' => 'relation', 'osmId' => 123]]],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function transitLinesFailsWithoutApiKey(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/transit-lines', [
            'json' => ['areas' => [['osmType' => 'relation', 'osmId' => 123]]],
        ]);

        self::assertResponseStatusCodeSame(401);
    }
}
