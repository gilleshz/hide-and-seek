<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\GtfsSource;
use App\Repository\GameAreaRepository;
use App\Repository\GtfsSourceRepository;
use App\Service\BoundaryService;
use App\Service\NominatimService;
use App\Service\OverpassHttpClient;
use App\Service\TransitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TransitLinePreviewApiTest extends ApiTestCase
{
    private const string GEOMETRY_RESPONSE = '{"elements":[{"type":"relation","id":100,"members":['
        . '{"type":"way","role":"","geometry":[{"lat":46.50,"lon":6.60},{"lat":46.55,"lon":6.65}]}]}]}';

    private ?string $fixtureDir = null;
    private ?string $sourceUuid = null;

    protected static ?bool $alwaysBootKernel = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->replaceTransitService();
    }

    protected function tearDown(): void
    {
        if ($this->sourceUuid !== null) {
            $container = self::getContainer();
            /** @var GtfsSourceRepository $sources */
            $sources = $container->get(GtfsSourceRepository::class);
            $source = $sources->findByUuid($this->sourceUuid);
            if ($source !== null) {
                $sources->remove($source);
            }
        }
        if ($this->fixtureDir !== null) {
            (new Filesystem())->remove($this->fixtureDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function itStillPreviewsSelectedOsmRelations(): void
    {
        $client = static::createClient();
        $response = $client->request('POST', '/api/transit-line-preview', self::AUTH + [
            'json' => ['osmIds' => ['100']],
        ]);

        self::assertResponseStatusCodeSame(201);
        $features = $this->features($response);
        self::assertCount(1, $features);
        $properties = $features[0]['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertSame('100', $properties['osmId'] ?? null);
    }

    #[Test]
    public function itPreviewsTheShapesOfSelectedGtfsRoutes(): void
    {
        $uuid = $this->createSource();

        $client = static::createClient();
        $response = $client->request('POST', '/api/transit-line-preview', self::AUTH + [
            'json' => ['gtfsLines' => [['gtfsSourceUuid' => $uuid, 'routeId' => 'R1']]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $features = $this->features($response);
        self::assertCount(1, $features);
        $geometry = $features[0]['geometry'] ?? null;
        self::assertIsArray($geometry);
        self::assertSame('LineString', $geometry['type'] ?? null);
        self::assertSame([[6.6, 46.5], [6.61, 46.51]], $geometry['coordinates'] ?? null);
    }

    #[Test]
    public function itMergesOsmRelationsAndGtfsShapesInOnePreview(): void
    {
        $uuid = $this->createSource();

        $client = static::createClient();
        $response = $client->request('POST', '/api/transit-line-preview', self::AUTH + [
            'json' => [
                'osmIds' => ['100'],
                'gtfsLines' => [['gtfsSourceUuid' => $uuid, 'routeId' => 'R1']],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(2, $this->features($response));
    }

    #[Test]
    public function itThinsLongGtfsShapesButKeepsBothEnds(): void
    {
        $uuid = $this->createSource();

        $client = static::createClient();
        $response = $client->request('POST', '/api/transit-line-preview', self::AUTH + [
            'json' => ['gtfsLines' => [['gtfsSourceUuid' => $uuid, 'routeId' => 'R2']]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $geometry = $this->features($response)[0]['geometry'] ?? null;
        self::assertIsArray($geometry);
        $coordinates = $geometry['coordinates'] ?? null;
        self::assertIsArray($coordinates);
        self::assertLessThanOrEqual(201, count($coordinates));
        self::assertGreaterThan(2, count($coordinates));
        /** @var list<float> $first */
        $first = $coordinates[0];
        /** @var list<float> $last */
        $last = $coordinates[count($coordinates) - 1];
        self::assertEqualsWithDelta(6.6, $first[0], 1e-9);
        self::assertEqualsWithDelta(46.5, $first[1], 1e-9);
        self::assertEqualsWithDelta(6.6299, $last[0], 1e-9);
        self::assertEqualsWithDelta(46.5299, $last[1], 1e-9);
    }

    #[Test]
    public function itRejectsAnEmptySelection(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/transit-line-preview', self::AUTH + ['json' => []]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'transit.preview_no_geometry']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function features(ResponseInterface $response): array
    {
        $body = $response->toArray();
        $geoJson = $body['geoJson'] ?? null;
        self::assertIsString($geoJson);
        $decoded = json_decode($geoJson, true, 512, JSON_THROW_ON_ERROR);
        $features = is_array($decoded) ? ($decoded['features'] ?? null) : null;
        self::assertIsArray($features);

        $parsed = [];
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            /** @var array<string, mixed> $feature */
            $parsed[] = $feature;
        }

        return $parsed;
    }

    private function createSource(): string
    {
        $this->fixtureDir = sys_get_temp_dir() . '/gtfs-preview-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->fixtureDir);
        $path = $this->fixtureDir . '/feed.zip';

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach (
            [
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3\nR2,2,Two,3",
            'trips.txt' => "route_id,service_id,trip_id,shape_id\nR1,S1,T1,SH1\nR2,S1,T2,SH2",
            'shapes.txt' => "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\n"
                . "SH1,46.5,6.6,1\nSH1,46.51,6.61,2\n" . self::longShape(),
            'stops.txt' => "stop_id,stop_name,stop_lat,stop_lon\nS1,Stop,46.5,6.6",
            'stop_times.txt' => "trip_id,stop_id,stop_sequence\nT1,S1,1",
            ] as $name => $content
        ) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $source = new GtfsSource('Fixture feed', $path);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist($source);
        $entityManager->flush();
        $this->sourceUuid = $source->getUuid();

        return $source->getUuid();
    }

    private static function longShape(): string
    {
        $rows = [];
        for ($i = 0; $i < 300; ++$i) {
            $rows[] = sprintf('SH2,%.6f,%.6f,%d', 46.5 + $i / 10000, 6.6 + $i / 10000, $i + 1);
        }

        return implode("\n", $rows);
    }

    private function replaceTransitService(): void
    {
        $container = static::getContainer();

        $nominatim = new NominatimService(
            new MockHttpClient(fn (): MockResponse => new MockResponse('{"address":{"country_code":"ch"}}')),
            new ArrayAdapter(),
            'test-agent',
            'https://nominatim.test',
        );
        /** @var GameAreaRepository $gameAreaRepository */
        $gameAreaRepository = $container->get(GameAreaRepository::class);
        $boundaryService = new BoundaryService($nominatim, $gameAreaRepository);

        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse(self::GEOMETRY_RESPONSE));
        $container->set(TransitService::class, new TransitService(
            new OverpassHttpClient($httpClient, 'https://test.example/api/', false),
            32_000_000,
            $boundaryService,
        ));
    }
}
