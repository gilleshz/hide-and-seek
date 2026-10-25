<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\GtfsSource;
use App\Exception\FunctionalException;
use App\Repository\GtfsSourceRepository;
use App\Service\GtfsService;
use App\Service\GtfsShapeCompletionService;
use App\Service\OutboundHttpFetcher;
use App\Service\OutboundUrlGuard;
use App\Service\OverpassHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class GtfsServiceTest extends TestCase
{
    private const string COMPLETION_DISCOVERY = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":100,"tags":{"route":"tram","ref":"A"}}
        ]}
        JSON;

    private const string COMPLETION_GEOMETRY = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":100,"members":[
           {"type":"way","role":"","geometry":[
             {"lat":48.5001,"lon":7.6999},{"lat":48.5001,"lon":7.7100},{"lat":48.5001,"lon":7.7201}
           ]}
         ]}
        ]}
        JSON;

    private const string COMPLETION_DISCOVERY_WITHOUT_MATCH = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":999,"tags":{"route":"tram","ref":"Z"}}
        ]}
        JSON;

    private GtfsService $service;
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/gtfs-test-' . bin2hex(random_bytes(4));
        mkdir($this->storageDir, 0o755, true);
        $this->service = $this->makeService(new MockHttpClient());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->storageDir);
        parent::tearDown();
    }

    #[Test]
    public function aSmallValidArchivePassesValidation(): void
    {
        $path = $this->writeZip([
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3",
            'trips.txt' => "route_id,service_id,trip_id\nR1,S1,T1",
            'shapes.txt' => "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\nSH1,46.5,6.6,1",
            'stops.txt' => "stop_id,stop_name\nS1,Stop",
            'stop_times.txt' => "trip_id,stop_id,stop_sequence\nT1,S1,1",
        ]);

        self::assertSame([], $this->service->validate($path));
    }

    #[Test]
    public function aFeedWithoutShapesGetsADedicatedErrorKey(): void
    {
        $path = $this->writeZip([
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3",
            'trips.txt' => "route_id,service_id,trip_id\nR1,S1,T1",
            'stops.txt' => "stop_id,stop_name\nS1,Stop",
            'stop_times.txt' => "trip_id,stop_id,stop_sequence\nT1,S1,1",
        ]);

        $this->expectInvalidArchive($path, 'gtfs.shapes_missing');
    }

    #[Test]
    public function anEntryBeyondTheUncompressedCapIsRejected(): void
    {
        $path = $this->writeZip([]);
        $big = $this->bigFile(257); // 257 MiB of zeros
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFile($big, 'stop_times.txt');
        $zip->setCompressionName('stop_times.txt', \ZipArchive::CM_STORE);
        $zip->close();
        @unlink($big);

        $exception = $this->expectInvalidArchive($path);
        self::assertStringContainsString('maximum uncompressed size', $exception->getMessage());
    }

    #[Test]
    public function anArchiveBeyondTheTotalUncompressedCapIsRejected(): void
    {
        $path = $this->writeZip([]);
        $first = $this->bigFile(520); // 2 x 520 MiB of zeros, deflated to a few KiB on disk
        $second = $this->bigFile(520);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFile($first, 'stop_times.txt');
        $zip->addFile($second, 'routes.txt');
        $zip->close();
        @unlink($first);
        @unlink($second);

        $exception = $this->expectInvalidArchive($path);
        self::assertStringContainsString('uncompressed size exceeds the maximum of 1024 MB', $exception->getMessage());
    }

    #[Test]
    public function anEntryBeyondTheCompressionRatioIsRejected(): void
    {
        $path = $this->writeZip([]);
        $zeros = $this->bigFile(10); // 10 MiB of zeros deflates to ~10 KiB, ratio ~1000
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFile($zeros, 'routes.txt');
        $zip->close();
        @unlink($zeros);

        $exception = $this->expectInvalidArchive($path);
        self::assertStringContainsString('compressed beyond the accepted ratio', $exception->getMessage());
    }

    #[Test]
    public function anArchiveWithTooManyEntriesIsStillRejected(): void
    {
        $path = $this->writeZip([]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        for ($i = 0; $i < 10_001; ++$i) {
            $zip->addFromString("file-{$i}", 'x');
        }
        $zip->close();

        $exception = $this->expectInvalidArchive($path);
        self::assertStringContainsString('too many entries', $exception->getMessage());
    }

    #[Test]
    public function aDownloadTransportErrorLeaksNoInternalDetailToTheClient(): void
    {
        $transport = new class extends \RuntimeException implements TransportExceptionInterface {
        };
        $client = new MockHttpClient(static function () use ($transport): MockResponse {
            throw $transport;
        });
        $service = $this->makeService($client);

        try {
            $service->createFromUrl('https://example.com/gtfs.zip', 'Feed');
            self::fail('Expected FunctionalException for the failed download.');
        } catch (FunctionalException $e) {
            self::assertSame('Failed to download GTFS feed.', $e->getMessage());
            self::assertSame('gtfs.download_failed', $e->getErrorKey());
            self::assertStringNotContainsString('example.com', $e->getMessage());
        }
    }

    #[Test]
    public function itRejectsPrivateReservedAndLinkLocalRanges(): void
    {
        $urls = [
            'https://10.0.0.5/x.zip',
            'https://100.64.0.1/x.zip',
            'https://100.65.0.1/x.zip',
            'https://169.254.0.1/x.zip',
            'https://169.254.169.254/x.zip',
            'https://198.18.0.1/x.zip',
        ];
        foreach ($urls as $url) {
            try {
                $this->service->createFromUrl($url, 'Feed');
                self::fail("Expected private-IP rejection for {$url}");
            } catch (FunctionalException $e) {
                self::assertSame('gtfs.private_ip_blocked', $e->getErrorKey());
            }
        }
    }

    #[Test]
    public function itRejectsIPv6LiteralsExplicitly(): void
    {
        try {
            $this->service->createFromUrl('https://[2001:db8::1]/x.zip', 'Feed');
            self::fail('Expected an IPv6 rejection.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.ipv6_not_supported', $e->getErrorKey());
        }
    }

    #[Test]
    public function itPinsTheFetchToTheBlocklistCheckedIp(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;
            throw new class extends \RuntimeException implements TransportExceptionInterface {
            };
        });
        $service = $this->makeService($client);

        try {
            $service->createFromUrl('https://8.8.8.8/gtfs.zip', 'Feed');
            self::fail('Expected FunctionalException for the failed download.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.download_failed', $e->getErrorKey());
        }

        self::assertIsArray($captured);
        self::assertSame(['8.8.8.8' => '8.8.8.8'], $captured['resolve']);
    }

    #[Test]
    public function itFallsBackWhenThePrimaryReturnsAnError(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse($this->validZipContents()),
        ]);

        $source = $this->makeService($client)->createFromUrl(
            'https://8.8.8.8/primary.zip',
            'Feed',
            null,
            'https://1.1.1.1/fallback.zip',
        );

        self::assertSame('https://1.1.1.1/fallback.zip', $source->getSourceUrl());
        self::assertSame(1, $source->getRouteCount());
        self::assertSame(2, $client->getRequestsCount());
    }

    #[Test]
    public function itFallsBackWhenThePrimaryIsNotAValidArchive(): void
    {
        $client = new MockHttpClient([
            new MockResponse('not a zip'),
            new MockResponse($this->validZipContents()),
        ]);

        $source = $this->makeService($client)->createFromUrl(
            'https://8.8.8.8/primary.zip',
            'Feed',
            null,
            'https://1.1.1.1/fallback.zip',
        );

        self::assertSame('https://1.1.1.1/fallback.zip', $source->getSourceUrl());
        self::assertSame(1, $source->getRouteCount());
    }

    #[Test]
    public function itDoesNotRequestTheFallbackWhenThePrimarySucceeds(): void
    {
        $client = new MockHttpClient([new MockResponse($this->validZipContents())]);

        $source = $this->makeService($client)->createFromUrl(
            'https://8.8.8.8/primary.zip',
            'Feed',
            null,
            'https://1.1.1.1/fallback.zip',
        );

        self::assertSame('https://8.8.8.8/primary.zip', $source->getSourceUrl());
        self::assertSame(1, $client->getRequestsCount());
    }

    #[Test]
    public function itThrowsWhenThePrimaryAndTheFallbackBothFail(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 404]),
        ]);

        try {
            $this->makeService($client)->createFromUrl(
                'https://8.8.8.8/primary.zip',
                'Feed',
                null,
                'https://1.1.1.1/fallback.zip',
            );
            self::fail('Expected FunctionalException when both candidates fail.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.download_failed', $e->getErrorKey());
        }

        self::assertSame([], glob($this->storageDir . '/*') ?: []);
    }

    #[Test]
    public function itBlocksAFallbackOnAPrivateIp(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 500])]);

        try {
            $this->makeService($client)->createFromUrl(
                'https://8.8.8.8/primary.zip',
                'Feed',
                null,
                'https://10.0.0.1/fallback.zip',
            );
            self::fail('Expected the fallback private IP to be blocked.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.private_ip_blocked', $e->getErrorKey());
        }
    }

    #[Test]
    public function itStoresAnOptionalRealtimeUrl(): void
    {
        $source = $this->service->createFromUpload(
            new UploadedFile($this->validZip(), 'feed.zip', 'application/zip', null, true),
            'Feed',
            'https://example.com/rt',
        );

        self::assertSame('https://example.com/rt', $source->getRealtimeUrl());
        self::assertSame(1, $source->getRouteCount());
    }

    #[Test]
    public function itRejectsAnInsecureRealtimeUrlBeforeDownloading(): void
    {
        $client = new MockHttpClient();

        try {
            $this->makeService($client)->createFromUrl(
                'https://8.8.8.8/feed.zip',
                'Feed',
                'http://example.com/rt',
            );
            self::fail('Expected the insecure realtime URL to be rejected.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.https_only', $e->getErrorKey());
        }

        self::assertSame(0, $client->getRequestsCount());
        self::assertSame([], glob($this->storageDir . '/*') ?: []);
    }

    #[Test]
    public function itRejectsAnInsecureRealtimeUrl(): void
    {
        try {
            $this->service->createFromUpload(
                new UploadedFile($this->validZip(), 'feed.zip', 'application/zip', null, true),
                'Feed',
                'http://example.com/rt',
            );
            self::fail('Expected the insecure realtime URL to be rejected.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.https_only', $e->getErrorKey());
        }
    }

    #[Test]
    public function aSmallSourcePassesSourceValidationAndDoesNotRequireASubset(): void
    {
        $source = new GtfsSource('Feed', $this->validZip());

        self::assertSame([], $this->service->validateSource($source->getFilePath()));
        $metadata = $this->service->sourceMetadata($source);
        self::assertFalse($metadata['requiresSubset']);
        self::assertGreaterThan(0, $metadata['sizeBytes']);
        self::assertGreaterThan(0, $metadata['uncompressedBytes']);
    }

    #[Test]
    public function aLargeSourcePassesSourceValidationButRequiresAValidatedSubset(): void
    {
        $path = $this->largeStopTimesZip(257);
        $source = new GtfsSource('Large Feed', $path);

        self::assertSame([], $this->service->validateSource($path));
        self::assertTrue($this->service->sourceMetadata($source)['requiresSubset']);

        $exception = $this->expectInvalidArchive($path);
        self::assertStringContainsString('maximum uncompressed size', $exception->getMessage());
    }

    #[Test]
    public function aSourceEntryOverTwoGigabytesIsRejected(): void
    {
        $path = $this->zipWithBigEntry(2049);

        try {
            $this->service->validateSource($path);
            self::fail('Expected the source entry cap to reject the archive.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.source_uncompressed_too_large', $e->getErrorKey());
        }
    }

    #[Test]
    public function aSourceOverFourGigabytesUncompressedIsRejected(): void
    {
        $path = $this->zipWithTwoBigEntries(2049, 2049);

        try {
            $this->service->validateSource($path);
            self::fail('Expected the source total cap to reject the archive.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.source_uncompressed_too_large', $e->getErrorKey());
        }
    }

    #[Test]
    public function aSourceOverTheCompressedCapIsRejected(): void
    {
        $path = $this->bigFile(513);

        try {
            $this->service->validateSource($path);
            self::fail('Expected the compressed source cap to reject the archive.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.source_too_large', $e->getErrorKey());
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function aSourceBeyondTheCompressionRatioIsRejected(): void
    {
        $path = $this->writeZip([]);
        $zeros = $this->bigFile(10);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFile($zeros, 'routes.txt');
        $zip->close();
        @unlink($zeros);

        try {
            $this->service->validateSource($path);
            self::fail('Expected the source ratio cap to reject the archive.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.invalid_archive', $e->getErrorKey());
            self::assertStringContainsString('compressed beyond the accepted ratio', $e->getMessage());
        }
    }

    #[Test]
    public function aShapelessSourceGetsTheShapesKey(): void
    {
        $path = $this->writeZip([
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3",
            'trips.txt' => "route_id,service_id,trip_id\nR1,S1,T1",
            'stops.txt' => "stop_id,stop_name\nS1,Stop",
            'stop_times.txt' => "trip_id,stop_id,stop_sequence\nT1,S1,1",
        ]);

        try {
            $this->service->validateSource($path);
            self::fail('Expected the missing shapes key.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.shapes_missing', $e->getErrorKey());
        }
    }

    #[Test]
    public function anUploadWithoutShapesIsCompletedFromOsm(): void
    {
        $overpass = new MockHttpClient([
            new MockResponse(self::COMPLETION_DISCOVERY),
            new MockResponse(self::COMPLETION_GEOMETRY),
        ]);
        $service = $this->makeService(new MockHttpClient(), $overpass);
        $path = $this->writeZip([
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,A,Line A,0",
            'trips.txt' => "route_id,service_id,trip_id\nR1,S1,T1",
            'stops.txt' => "stop_id,stop_name,stop_lat,stop_lon\n"
                . "S1,Alpha,48.5000,7.7000\nS2,Beta,48.5000,7.7100\nS3,Gamma,48.5000,7.7200",
            'stop_times.txt' => "trip_id,stop_id,stop_sequence\nT1,S1,1\nT1,S2,2\nT1,S3,3",
        ]);

        $source = $service->createFromUpload(
            new UploadedFile($path, 'feed.zip', 'application/zip', null, true),
            'Feed',
        );

        self::assertSame(1, $source->getRouteCount());
        self::assertSame(2, $overpass->getRequestsCount());

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($source->getFilePath()));
        $shapes = (string) $zip->getFromName('shapes.txt');
        $zip->close();

        self::assertStringContainsString('OSM_100', $shapes);
    }

    #[Test]
    public function anUploadWithAnEmptyShapesStubThatCannotBeCompletedIsRejected(): void
    {
        $overpass = new MockHttpClient([
            new MockResponse(self::COMPLETION_DISCOVERY_WITHOUT_MATCH),
        ]);
        $service = $this->makeService(new MockHttpClient(), $overpass);
        $path = $this->writeZip([
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,A,Line A,0",
            'trips.txt' => "route_id,service_id,trip_id\nR1,S1,T1",
            'shapes.txt' => "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\n",
            'stops.txt' => "stop_id,stop_name,stop_lat,stop_lon\n"
                . "S1,Alpha,48.5000,7.7000\nS2,Beta,48.5000,7.7100\nS3,Gamma,48.5000,7.7200",
            'stop_times.txt' => "trip_id,stop_id,stop_sequence\nT1,S1,1\nT1,S2,2\nT1,S3,3",
        ]);

        try {
            $service->createFromUpload(
                new UploadedFile($path, 'feed.zip', 'application/zip', null, true),
                'Feed',
            );
            self::fail('Expected an uncompletable shape-less feed to be rejected.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.shapes_missing', $e->getErrorKey());
        }

        self::assertSame([], glob($this->storageDir . '/*') ?: []);
    }

    #[Test]
    public function deleteFileRemovesTheSourceAndItsSubsetCache(): void
    {
        $path = $this->validZip();
        $source = new GtfsSource('Feed', $path, 'source-uuid');
        $subsetDir = dirname($path) . '/subsets/' . $source->getUuid();
        mkdir($subsetDir, 0o755, true);
        file_put_contents($subsetDir . '/subset.zip', 'cached');

        $this->service->deleteFile($source);

        self::assertFileDoesNotExist($path);
        self::assertDirectoryDoesNotExist($subsetDir);
    }

    #[Test]
    public function aSourceWithAMalformedShapesHeaderIsRejected(): void
    {
        $path = $this->writeZip([
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3",
            'trips.txt' => "route_id,service_id,trip_id\nR1,S1,T1",
            'shapes.txt' => "shape_id,lat,lon\nSH1,46.5,6.6\n",
            'stops.txt' => "stop_id,stop_name\nS1,Stop",
            'stop_times.txt' => "trip_id,stop_id,stop_sequence\nT1,S1,1",
        ]);

        try {
            $this->service->validateSource($path);
            self::fail('Expected the malformed shapes header to be rejected.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.invalid_archive', $e->getErrorKey());
            self::assertStringContainsString("Missing required column 'shape_pt_lat' in shapes.txt", $e->getMessage());
        }
    }

    private function largeStopTimesZip(int $sizeMiB): string
    {
        $path = $this->storageDir . '/large-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach (
            [
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3",
            'trips.txt' => "route_id,service_id,trip_id\nR1,S1,T1",
            'shapes.txt' => "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\nSH1,46.5,6.6,1",
            'stops.txt' => "stop_id,stop_name\nS1,Stop",
            ] as $name => $content
        ) {
            $zip->addFromString($name, $content);
        }
        $big = $this->bigStopTimesFile($sizeMiB);
        $zip->addFile($big, 'stop_times.txt');
        $zip->close();
        @unlink($big);

        return $path;
    }

    private function zipWithBigEntry(int $sizeMiB): string
    {
        $path = $this->writeZip([]);
        $big = $this->bigFile($sizeMiB);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFile($big, 'stop_times.txt');
        $zip->close();
        @unlink($big);

        return $path;
    }

    private function zipWithTwoBigEntries(int $firstMiB, int $secondMiB): string
    {
        $path = $this->writeZip([]);
        $first = $this->bigFile($firstMiB);
        $second = $this->bigFile($secondMiB);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFile($first, 'stop_times.txt');
        $zip->addFile($second, 'routes.txt');
        $zip->close();
        @unlink($first);
        @unlink($second);

        return $path;
    }

    private function bigStopTimesFile(int $sizeMiB): string
    {
        $path = $this->storageDir . '/big-stop-times-' . bin2hex(random_bytes(4));
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);

        $header = "trip_id,stop_id,stop_sequence\n";
        fwrite($handle, $header);
        $bytes = strlen($header);
        $target = $sizeMiB * 1024 * 1024;
        $i = 0;
        while ($bytes < $target) {
            $line = 'T' . ($i % 5000) . ',S' . ($i % 500) . ",1\n";
            fwrite($handle, $line);
            $bytes += strlen($line);
            ++$i;
        }
        fclose($handle);

        return $path;
    }

    private function makeService(MockHttpClient $client, ?MockHttpClient $overpassClient = null): GtfsService
    {
        return new GtfsService(
            $this->createStub(GtfsSourceRepository::class),
            $this->storageDir,
            new OutboundHttpFetcher($client, new OutboundUrlGuard(), new NullLogger()),
            new NullLogger(),
            new OutboundUrlGuard(),
            new Filesystem(),
            new GtfsShapeCompletionService(
                new OverpassHttpClient(
                    $overpassClient ?? new MockHttpClient(),
                    'https://test.example/api/',
                    false,
                    new NullLogger(),
                ),
                new NullLogger(),
                32_000_000,
            ),
        );
    }

    private function validZipContents(): string
    {
        return (string) file_get_contents($this->validZip());
    }

    private function validZip(): string
    {
        return $this->writeZip([
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3",
            'trips.txt' => "route_id,service_id,trip_id,shape_id\nR1,S1,T1,SH1",
            'shapes.txt' => "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\nSH1,46.5,6.6,1",
            'stops.txt' => "stop_id,stop_name\nS1,Stop",
            'stop_times.txt' => "trip_id,stop_id,stop_sequence\nT1,S1,1",
        ]);
    }

    /** @param array<string, string> $files */
    private function writeZip(array $files): string
    {
        $path = $this->storageDir . '/fixture-' . bin2hex(random_bytes(4)) . '.zip';
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0o755, true) && !is_dir($this->storageDir)) {
            throw new \RuntimeException("Failed to create directory: {$this->storageDir}");
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create fixture zip.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    /** Writes $sizeMiB MiB of zeros; the caller owns the file and unlinks it. */
    private function bigFile(int $sizeMiB): string
    {
        $path = $this->storageDir . '/big-' . bin2hex(random_bytes(4));
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        $chunk = str_repeat('0', 1024 * 1024);
        for ($i = 0; $i < $sizeMiB; ++$i) {
            fwrite($handle, $chunk);
        }
        fclose($handle);

        return $path;
    }

    private function expectInvalidArchive(
        string $path,
        string $errorKey = 'gtfs.invalid_archive',
    ): FunctionalException {
        try {
            $this->service->validate($path);
            self::fail('Expected FunctionalException for the invalid archive.');
        } catch (FunctionalException $e) {
            self::assertSame($errorKey, $e->getErrorKey());

            return $e;
        }
    }
}
