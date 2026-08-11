<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dto\GameInput;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Repository\FeatureRepository;
use App\Repository\GameAreaRepository;
use App\Repository\GameRepository;
use App\Service\BoundaryService;
use App\Service\GameService;
use App\Service\NominatimService;
use App\Service\OverpassHttpClient;
use App\Service\OverpassService;
use App\Service\TransitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GameBoundaryFromAreasTest extends KernelTestCase
{
    private const string LOOKUP_RESPONSE = <<<'JSON'
    [{"osm_type":"relation","osm_id":123,"display_name":"Testville",
      "address":{"admin_level":"8"},
      "geojson":{"type":"Polygon","coordinates":[[[6.60,46.50],[6.65,46.50],[6.65,46.55],[6.60,46.55],[6.60,46.50]]]}}]
    JSON;

    private const string TWO_ADJACENT_AREAS_RESPONSE = <<<'JSON'
    [
      {"osm_type":"relation","osm_id":200,"display_name":"Westville",
       "address":{"admin_level":"8"},
       "geojson":{"type":"Polygon","coordinates":[[[0,0],[1,0],[1,1],[0,1],[0,0]]]}},
      {"osm_type":"relation","osm_id":201,"display_name":"Eastville",
       "address":{"admin_level":"8"},
       "geojson":{"type":"Polygon","coordinates":[[[1,0],[2,0],[2,1],[1,1],[1,0]]]}}
    ]
    JSON;

    #[Test]
    public function createWithAreasBuildsAUnionBoundaryAndPersistsGameAreas(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $nominatim = new NominatimService(
            new MockHttpClient([
                new MockResponse(self::LOOKUP_RESPONSE),
                new MockResponse('{"address":{"country_code":"ch"}}'),
            ]),
            new ArrayAdapter(),
            'test-agent',
            'https://nominatim.test',
        );
        $container->set(NominatimService::class, $nominatim);

        $this->mockOverpassAndTransit($container);

        $service = $container->get(GameService::class);
        $games = $container->get(GameRepository::class);
        $gameAreas = $container->get(GameAreaRepository::class);
        self::assertInstanceOf(GameService::class, $service);
        self::assertInstanceOf(GameRepository::class, $games);
        self::assertInstanceOf(GameAreaRepository::class, $gameAreas);

        $input = new GameInput();
        $input->name = 'Boundary Test';
        $input->size = GameSize::Large;
        $input->edition = Edition::Metric;
        $input->areas = [['osmType' => 'relation', 'osmId' => 123]];

        $game = $service->create($input);

        self::assertNotSame('', $game->getUuid());
        self::assertCount(1, $gameAreas->findByGame($game));

        $boundary = $game->getBoundaryGeoJson();
        self::assertNotNull($boundary);
        self::assertStringContainsString('"type"', $boundary);
        self::assertStringContainsString('"coordinates"', $boundary);
    }

    #[Test]
    public function twoAdjacentAreasAreMergedIntoSingleFeature(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $nominatim = new NominatimService(
            new MockHttpClient([
                new MockResponse(self::TWO_ADJACENT_AREAS_RESPONSE),
                new MockResponse('{"address":{"country_code":"ch"}}'),
            ]),
            new ArrayAdapter(),
            'test-agent',
            'https://nominatim.test',
        );
        $container->set(NominatimService::class, $nominatim);

        $this->mockOverpassAndTransit($container);

        $service = $container->get(GameService::class);
        $gameAreas = $container->get(GameAreaRepository::class);
        self::assertInstanceOf(GameService::class, $service);
        self::assertInstanceOf(GameAreaRepository::class, $gameAreas);

        $input = new GameInput();
        $input->name = 'Two Areas';
        $input->size = GameSize::Medium;
        $input->edition = Edition::Metric;
        $input->areas = [
            ['osmType' => 'relation', 'osmId' => 200],
            ['osmType' => 'relation', 'osmId' => 201],
        ];

        $game = $service->create($input);

        self::assertCount(2, $gameAreas->findByGame($game));
        $boundary = $game->getBoundaryGeoJson();
        self::assertNotNull($boundary);

        $decoded = json_decode($boundary, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['type'] ?? null);
        self::assertIsArray($decoded['coordinates'] ?? null);
        self::assertNotEmpty($decoded['coordinates']);
    }

    #[Test]
    public function createWithNoGeometryFoundThrows(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $nominatim = new NominatimService(
            new MockHttpClient([new MockResponse('[]')]),
            new ArrayAdapter(),
            'test-agent',
            'https://nominatim.test',
        );
        $container->set(NominatimService::class, $nominatim);

        $this->mockOverpassAndTransit($container);

        $service = $container->get(GameService::class);
        self::assertInstanceOf(GameService::class, $service);

        $input = new GameInput();
        $input->name = 'No Geometry';
        $input->size = GameSize::Medium;
        $input->edition = Edition::Metric;
        $input->areas = [['osmType' => 'relation', 'osmId' => 999]];

        $this->expectExceptionMessage('No geometry found for the selected areas.');
        $service->create($input);
    }

    private static function mockOverpassAndTransit(ContainerInterface $container): void
    {
        if ($container->has(OverpassService::class)) {
            /** @var FeatureRepository $features */
            $features = $container->get(FeatureRepository::class);
            /** @var EntityManagerInterface $em */
            $em = $container->get(EntityManagerInterface::class);
            $overpass = new OverpassService(
                new OverpassHttpClient(new MockHttpClient([new MockResponse('{"elements":[]}')]), 'https://test.example/api/', false),
                $features,
                $em,
            );
            $container->set(OverpassService::class, $overpass);
        }
        if ($container->has(TransitService::class)) {
            /** @var BoundaryService $boundaryService */
            $boundaryService = $container->get(BoundaryService::class);
            $transit = new TransitService(
                new OverpassHttpClient(new MockHttpClient([new MockResponse('{"elements":[]}')]), 'https://test.example/api/', false),
                32_000_000,
                $boundaryService,
            );
            $container->set(TransitService::class, $transit);
        }
    }
}
