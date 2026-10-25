<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Repository\GtfsSourceRepository;
use App\Service\GtfsService;
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
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach (
            [
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3",
            'trips.txt' => "route_id,service_id,trip_id,shape_id\nR1,S1,T1,SH1",
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
