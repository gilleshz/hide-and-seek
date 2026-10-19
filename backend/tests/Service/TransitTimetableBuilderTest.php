<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class TransitTimetableBuilderTest extends TestCase
{
    private const string PYTHON = '/opt/osm2gtfs/bin/python';

    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/gtfs-timetable-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->base);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->base);
        parent::tearDown();
    }

    #[Test]
    public function itBuildsTheIndexWithMergedStopsAndOriginalStopIds(): void
    {
        $script = dirname(__DIR__, 2) . '/bin/gtfs_timetable.py';
        if (!is_file(self::PYTHON) || !is_file($script)) {
            self::markTestSkipped('The GTFS timetable Python runtime is not available.');
        }

        $merged = $this->base . '/merged';
        (new Filesystem())->mkdir($merged);
        $this->writeMergedFeed($merged);
        $sourceZip = $this->writeSourceZip();

        $specPath = $this->base . '/spec.json';
        file_put_contents($specPath, json_encode([
            'mergedDir' => $merged,
            'outputDir' => $this->base . '/out',
            'daysBack' => 2,
            'daysForward' => 60,
            'sources' => [['path' => $sourceZip, 'uuid' => 'source-1', 'selectedRefs' => ['L1']]],
        ], JSON_THROW_ON_ERROR));

        $process = new Process([self::PYTHON, $script, $specPath]);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $output = $this->base . '/out';
        $meta = $this->decode($output . '/meta.json');
        $stops = $this->decode($output . '/stops.json');

        self::assertSame('UTC', $meta['timezone']);
        $routes = $meta['routes'] ?? null;
        self::assertIsArray($routes);
        $route = $routes['1'] ?? null;
        self::assertIsArray($route);
        self::assertSame('L1', $route['ref']);

        $sources = $meta['sources'] ?? null;
        self::assertIsArray($sources);
        $source = $sources[0] ?? null;
        self::assertIsArray($source);
        self::assertSame('source-1', $source['uuid']);

        $serviceDays = $meta['serviceDays'] ?? null;
        self::assertIsArray($serviceDays);
        $today = $serviceDays[$this->today()] ?? null;
        self::assertIsArray($today);
        self::assertContains('S1', $today);
        self::assertArrayNotHasKey($this->tomorrow(), $serviceDays);

        $alphaStops = $stops['S_0.0000_0.0000'] ?? null;
        self::assertIsArray($alphaStops);
        self::assertContains(['src' => 0, 'stopId' => 'A1'], $alphaStops);
        self::assertContains(['src' => 0, 'stopId' => 'A2'], $alphaStops);

        $midStops = $stops['S_0.0000_0.0010'] ?? null;
        self::assertIsArray($midStops);
        self::assertContains(['src' => 0, 'stopId' => 'M1'], $midStops);

        $alpha = $this->decode($output . '/' . sha1('S_0.0000_0.0000') . '.json');
        self::assertCount(2, $alpha);
        $first = $alpha[0] ?? null;
        $second = $alpha[1] ?? null;
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame(28800, $first['seconds']);
        self::assertSame(90000, $second['seconds']);

        $mid = $this->decode($output . '/' . sha1('S_0.0000_0.0010') . '.json');
        self::assertCount(1, $mid);
        $midDeparture = $mid[0] ?? null;
        self::assertIsArray($midDeparture);
        self::assertSame(28860, $midDeparture['seconds']);
        self::assertFileDoesNotExist($output . '/' . sha1('S_0.0000_0.0020') . '.json');
    }

    private function writeMergedFeed(string $dir): void
    {
        $today = $this->today();
        $tomorrow = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))->format('Ymd');

        file_put_contents($dir . '/agency.txt', "agency_id,agency_name,agency_url,agency_timezone\nA,Test,https://test,UTC\n");
        file_put_contents(
            $dir . '/routes.txt',
            "route_id,agency_id,route_short_name,route_long_name,route_type,route_color\n1,A,L1,Line One,3,B22E75\n",
        );
        file_put_contents(
            $dir . '/trips.txt',
            "route_id,service_id,trip_id,trip_headsign,direction_id\n"
            . "1,S1,T1,Alpha,0\n"
            . "1,S1,T2,Beta,1\n",
        );
        file_put_contents(
            $dir . '/stops.txt',
            "stop_id,stop_name,stop_lat,stop_lon\n"
            . "S_0.0000_0.0000,Alpha Stop,0.0,0.0\n"
            . "S_0.0000_0.0010,Mid Stop,0.0,0.001\n"
            . "S_0.0000_0.0020,Beta Stop,0.0,0.002\n",
        );
        file_put_contents(
            $dir . '/stop_times.txt',
            "trip_id,arrival_time,departure_time,stop_id,stop_sequence\n"
            . "T1,08:00:00,08:00:00,S_0.0000_0.0000,1\n"
            . "T1,08:01:00,08:01:00,S_0.0000_0.0010,2\n"
            . "T1,08:02:00,08:02:00,S_0.0000_0.0020,3\n"
            . "T2,25:00:00,25:00:00,S_0.0000_0.0000,1\n"
            . "T2,25:01:00,25:01:00,S_0.0000_0.0010,2\n",
        );
        file_put_contents(
            $dir . '/calendar.txt',
            "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\n"
            . "S1,1,1,1,1,1,1,1,20260101,20261231\n",
        );
        file_put_contents(
            $dir . '/calendar_dates.txt',
            "service_id,date,exception_type\nS1,{$today},1\nS1,{$tomorrow},2\n",
        );
    }

    private function writeSourceZip(): string
    {
        $path = $this->base . '/source.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString(
            'stops.txt',
            "stop_id,stop_name,stop_lat,stop_lon\n"
            . "A1,Alpha Stop,0.0,0.0\n"
            . "A2,Alpha Stop,0.0,0.0005\n"
            . "M1,Mid Stop,0.0,0.001\n"
            . "B1,Beta Stop,0.0,0.002\n",
        );
        $zip->close();

        return $path;
    }

    /** @return array<mixed, mixed> */
    private function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
    }

    private function tomorrow(): string
    {
        return (new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))->format('Y-m-d');
    }
}
