<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Enum\RealtimeProvider;
use App\Repository\GtfsSourceRepository;
use App\Service\GtfsService;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class GtfsSourceApiTest extends ApiTestCase
{
    private ?string $sourceUuid = null;
    private ?string $fixturePath = null;

    #[Test]
    public function itUploadsAFeedAndReturnsItsSourceMetadata(): void
    {
        $client = static::createClient();
        $this->fixturePath = $this->writeZip();

        $response = $client->request('POST', '/api/gtfs-sources', [
            'headers' => ['Content-Type' => 'multipart/form-data'] + self::AUTH['headers'],
            'extra' => [
                'parameters' => ['name' => 'Fixture feed'],
                'files' => ['file' => new UploadedFile($this->fixturePath, 'feed.zip', 'application/zip', null, true)],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertIsString($data['uuid']);
        $this->sourceUuid = $data['uuid'];
        self::assertSame('Fixture feed', $data['name']);
        self::assertFalse($data['requiresSubset']);
        self::assertGreaterThan(0, $data['sizeBytes']);
        self::assertGreaterThan(0, $data['uncompressedBytes']);
        self::assertIsArray($data['routes']);
        $routes = $data['routes'];
        self::assertCount(1, $routes);
        self::assertIsArray($routes[0]);
        self::assertSame('R1', $routes[0]['routeId']);
        self::assertSame('1', $routes[0]['shortName']);
        self::assertSame('Agency', $routes[0]['agencyName']);
        self::assertArrayNotHasKey('tripsPerDay', $routes[0]);
        self::assertArrayNotHasKey('minTripsPerDay', $data);
    }

    #[Test]
    public function itTreatsARealtimeUrlWithoutAProviderAsGtfsRt(): void
    {
        $client = static::createClient();
        $this->fixturePath = $this->writeZip();

        $response = $client->request('POST', '/api/gtfs-sources', [
            'headers' => ['Content-Type' => 'multipart/form-data'] + self::AUTH['headers'],
            'extra' => [
                'parameters' => ['name' => 'Legacy feed', 'realtimeUrl' => 'https://8.8.8.8/rt'],
                'files' => ['file' => new UploadedFile($this->fixturePath, 'feed.zip', 'application/zip', null, true)],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertIsString($data['uuid']);
        $this->sourceUuid = $data['uuid'];

        $sources = self::getContainer()->get(GtfsSourceRepository::class);
        self::assertInstanceOf(GtfsSourceRepository::class, $sources);
        $source = $sources->findByUuid($data['uuid']);
        self::assertNotNull($source);
        self::assertSame(RealtimeProvider::GtfsRtUrl, $source->getRealtimeProvider());
        self::assertSame('https://8.8.8.8/rt', $source->getRealtimeUrl());
        self::assertNull($source->getRealtimeProfile());
    }

    #[Test]
    public function itStoresAnOptionalRealtimeAlertsUrl(): void
    {
        $client = static::createClient();
        $this->fixturePath = $this->writeZip();

        $response = $client->request('POST', '/api/gtfs-sources', [
            'headers' => ['Content-Type' => 'multipart/form-data'] + self::AUTH['headers'],
            'extra' => [
                'parameters' => [
                    'name' => 'Alerts feed',
                    'realtimeUrl' => 'https://8.8.8.8/rt',
                    'realtimeAlertsUrl' => 'https://8.8.8.8/alerts.pb',
                ],
                'files' => ['file' => new UploadedFile($this->fixturePath, 'feed.zip', 'application/zip', null, true)],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        $this->sourceUuid = is_string($data['uuid'] ?? null) ? $data['uuid'] : null;

        $sources = self::getContainer()->get(GtfsSourceRepository::class);
        self::assertInstanceOf(GtfsSourceRepository::class, $sources);
        $source = $sources->findByUuid((string) $this->sourceUuid);
        self::assertNotNull($source);
        self::assertSame(RealtimeProvider::GtfsRtUrl, $source->getRealtimeProvider());
        self::assertSame('https://8.8.8.8/rt', $source->getRealtimeUrl());
        self::assertSame('https://8.8.8.8/alerts.pb', $source->getRealtimeAlertsUrl());
    }

    #[Test]
    public function itRejectsANonHttpsRealtimeAlertsUrl(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/gtfs-sources', self::AUTH + [
            'json' => [
                'url' => 'https://8.8.8.8/feed.zip',
                'name' => 'Alerts feed',
                'realtimeAlertsUrl' => 'http://8.8.8.8/alerts.pb',
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'gtfs_source.realtime_alerts_url_invalid']);
    }

    #[Test]
    public function itReadsTheRealtimeProviderFromMultipartFields(): void
    {
        $client = static::createClient();
        $this->fixturePath = $this->writeZip();

        $client->request('POST', '/api/gtfs-sources', [
            'headers' => ['Content-Type' => 'multipart/form-data'] + self::AUTH['headers'],
            'extra' => [
                'parameters' => ['name' => 'SIRI feed', 'realtimeProvider' => 'carrier_pigeon'],
                'files' => ['file' => new UploadedFile($this->fixturePath, 'feed.zip', 'application/zip', null, true)],
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'gtfs_source.realtime_provider_invalid']);
    }

    #[Test]
    public function itRejectsASiriProviderWithoutAProfile(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/gtfs-sources', self::AUTH + [
            'json' => [
                'url' => 'https://8.8.8.8/feed.zip',
                'name' => 'SIRI feed',
                'realtimeProvider' => 'siri_lite',
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'gtfs_source.realtime_profile_required']);
    }

    #[Test]
    public function itRejectsAnUnknownSiriProfile(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/gtfs-sources', self::AUTH + [
            'json' => [
                'url' => 'https://8.8.8.8/feed.zip',
                'name' => 'SIRI feed',
                'realtimeProvider' => 'siri_lite',
                'realtimeProfile' => 'unknown',
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'gtfs_source.realtime_profile_unknown']);
    }

    #[Test]
    public function itRejectsAnInvalidRealtimeProvider(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/gtfs-sources', self::AUTH + [
            'json' => [
                'url' => 'https://8.8.8.8/feed.zip',
                'name' => 'SIRI feed',
                'realtimeProvider' => 'carrier_pigeon',
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'gtfs_source.realtime_provider_invalid']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function itRejectsAnUnavailableSiriProfile(): void
    {
        foreach (['PRIM_API_KEY', 'CTS_API_TOKEN', 'SYTRAL_SIRI_USER', 'SYTRAL_SIRI_PASSWORD'] as $var) {
            putenv($var);
            unset($_ENV[$var], $_SERVER[$var]);
        }

        $client = static::createClient();

        $client->request('POST', '/api/gtfs-sources', self::AUTH + [
            'json' => [
                'url' => 'https://8.8.8.8/feed.zip',
                'name' => 'SIRI feed',
                'realtimeProvider' => 'siri_lite',
                'realtimeProfile' => 'idfm',
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'gtfs_source.realtime_profile_unavailable']);
    }

    protected function tearDown(): void
    {
        if ($this->sourceUuid !== null) {
            $container = self::getContainer();
            $sources = $container->get(GtfsSourceRepository::class);
            self::assertInstanceOf(GtfsSourceRepository::class, $sources);
            $gtfs = $container->get(GtfsService::class);
            self::assertInstanceOf(GtfsService::class, $gtfs);

            $source = $sources->findByUuid($this->sourceUuid);
            if ($source !== null) {
                $gtfs->deleteFile($source);
                $sources->remove($source);
            }
        }
        if ($this->fixturePath !== null && is_file($this->fixturePath)) {
            @unlink($this->fixturePath);
        }

        parent::tearDown();
    }

    private function writeZip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gtfs-api-fixture-') . '.zip';
        $trips = "route_id,service_id,trip_id,shape_id\n";
        for ($i = 1; $i <= 10; ++$i) {
            $trips .= "R1,S1,T{$i},SH1\n";
        }
        $trips .= "R2,S1,SLOW,\n";

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach (
            [
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,agency_id,route_short_name,route_long_name,route_type\n"
                . "R1,A1,1,One,3\nR2,A1,2,Two,3",
            'trips.txt' => $trips,
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,"
                . "start_date,end_date\n"
                . "S1,1,1,1,1,1,0,0,20260101,20261231",
            'shapes.txt' => "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\nSH1,46.5,6.6,1",
            'stops.txt' => "stop_id,stop_name\nS1,Stop",
            'stop_times.txt' => "trip_id,stop_id,stop_sequence\nT1,S1,1",
            ] as $name => $content
        ) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }
}
