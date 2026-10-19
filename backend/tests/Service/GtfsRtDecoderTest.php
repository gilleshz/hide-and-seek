<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class GtfsRtDecoderTest extends TestCase
{
    private const string PYTHON = '/opt/osm2gtfs/bin/python';

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/gtfs-rt-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
        parent::tearDown();
    }

    #[Test]
    public function itDecodesTripUpdatesIntoTheApiShape(): void
    {
        $decoder = dirname(__DIR__, 2) . '/bin/gtfs_rt_decode.py';
        if (!is_file(self::PYTHON) || !is_file($decoder)) {
            self::markTestSkipped('The GTFS-RT Python runtime is not available.');
        }
        $probe = new Process([self::PYTHON, '-c', 'import google.transit.gtfs_realtime_pb2']);
        $probe->run();
        if (!$probe->isSuccessful()) {
            self::markTestSkipped('gtfs-realtime-bindings is not installed.');
        }

        $bytes = $this->feedBytes($probe);
        $process = new Process([self::PYTHON, $decoder], null, null, $bytes, 5);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('trip_update', $decoded['type']);
        self::assertSame(123, $decoded['headerTimestamp']);

        $trips = $decoded['trips'] ?? null;
        self::assertIsArray($trips);
        $trip = $trips['T1'] ?? null;
        self::assertIsArray($trip);
        self::assertSame('20261018', $trip['startDate']);

        $stops = $trip['stops'] ?? null;
        self::assertIsArray($stops);
        $stop = $stops[0] ?? null;
        self::assertIsArray($stop);
        self::assertSame('S1', $stop['stopId']);
        self::assertSame(1, $stop['seq']);
        self::assertSame(456, $stop['time']);
    }

    private function feedBytes(Process $probe): string
    {
        $path = $this->workDir . '/feed.pb';
        $code = <<<'PY'
            import sys
            from google.transit import gtfs_realtime_pb2 as pb
            feed = pb.FeedMessage()
            feed.header.gtfs_realtime_version = "2.0"
            feed.header.timestamp = 123
            entity = feed.entity.add()
            entity.id = "1"
            entity.trip_update.trip.trip_id = "T1"
            entity.trip_update.trip.start_date = "20261018"
            stop = entity.trip_update.stop_time_update.add()
            stop.stop_id = "S1"
            stop.stop_sequence = 1
            stop.departure.time = 456
            open(sys.argv[1], "wb").write(feed.SerializeToString())
            PY;

        $generator = new Process([self::PYTHON, '-c', $code, $path]);
        $generator->run();
        self::assertTrue($generator->isSuccessful(), $generator->getErrorOutput());
        self::assertFileExists($path);
        self::assertTrue($probe->isSuccessful());

        return (string) file_get_contents($path);
    }
}
