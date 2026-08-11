<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\FunctionalException;
use App\Repository\GtfsSourceRepository;
use App\Service\GtfsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class GtfsServiceTest extends TestCase
{
    private GtfsService $service;
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/gtfs-test-' . bin2hex(random_bytes(4));
        $this->service = new GtfsService(
            $this->createStub(GtfsSourceRepository::class),
            $this->storageDir,
            new MockHttpClient(),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            foreach (glob($this->storageDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->storageDir);
        }
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
        $service = new GtfsService(
            $this->createStub(GtfsSourceRepository::class),
            $this->storageDir,
            $client,
            new NullLogger(),
        );

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
        $service = new GtfsService($this->createStub(GtfsSourceRepository::class), $this->storageDir, $client, new NullLogger());

        try {
            $service->createFromUrl('https://8.8.8.8/gtfs.zip', 'Feed');
            self::fail('Expected FunctionalException for the failed download.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.download_failed', $e->getErrorKey());
        }

        self::assertIsArray($captured);
        self::assertSame(['8.8.8.8' => '8.8.8.8'], $captured['resolve']);
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

    private function expectInvalidArchive(string $path): FunctionalException
    {
        try {
            $this->service->validate($path);
            self::fail('Expected FunctionalException for the invalid archive.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.invalid_archive', $e->getErrorKey());

            return $e;
        }
    }
}
