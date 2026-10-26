<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GtfsTripVariantFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class GtfsTripVariantFilterTest extends TestCase
{
    private string $storageDir;
    private GtfsTripVariantFilter $filter;

    /** @var list<string> */
    private array $outputs = [];

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/gtfs-variants-test-' . bin2hex(random_bytes(4));
        mkdir($this->storageDir, 0o755, true);
        $this->filter = new GtfsTripVariantFilter(new Filesystem());
    }

    protected function tearDown(): void
    {
        foreach ($this->outputs as $output) {
            @unlink($output);
        }
        (new Filesystem())->remove($this->storageDir);
        parent::tearDown();
    }

    #[Test]
    public function itDropsTripsWhoseFirstOrLastStopIsRareForItsRoute(): void
    {
        $source = $this->feed($this->rareEndpointFeed());

        $filtered = $this->filter($source, ['R1', 'R2']);

        self::assertNotSame($source, $filtered);
        $trips = $this->readCsv($filtered, 'trips.txt');
        self::assertNotContains('X1', array_column($trips, 'trip_id'));
        self::assertContains('T1', array_column($trips, 'trip_id'));
        self::assertContains('U1', array_column($trips, 'trip_id'), 'the other route is untouched');

        $stopTimes = $this->readCsv($filtered, 'stop_times.txt');
        self::assertSame([], array_values(array_filter(
            $stopTimes,
            static fn (array $row): bool => $row['trip_id'] === 'X1',
        )));
        self::assertCount(396, $stopTimes);
    }

    #[Test]
    public function itKeepsARareStopThatIsNotAnEndpoint(): void
    {
        $source = $this->feed([
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3",
            'trips.txt' => $this->rows(['route_id', 'service_id', 'trip_id'], [
                ['R1', 'S1', 'T1'],
                ...array_map(static fn (int $i): array => ['R1', 'S1', 'T' . $i], range(2, 100)),
            ]),
            'stop_times.txt' => $this->stopTimes([
                ['T1', 'S1', 1], ['T1', 'S9', 2], ['T1', 'S2', 3],
                ...$this->regularStopTimes(2, 100),
            ]),
            'stops.txt' => $this->stops(['S1' => 'Main', 'S2' => 'End', 'S9' => 'Detour']),
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\n"
                . "S1,1,1,1,1,1,0,0,20260101,20261231",
            'calendar_dates.txt' => "service_id,date,exception_type\n",
        ]);

        $filtered = $this->filter($source, ['R1']);

        self::assertSame($source, $filtered);
        self::assertCount(100, $this->readCsv($filtered, 'trips.txt'));
    }

    #[Test]
    public function itGroupsPlatformVariantsByStopName(): void
    {
        $trips = $this->rows(['route_id', 'service_id', 'trip_id'], array_map(
            static fn (int $i): array => ['R1', 'S1', 'T' . $i],
            range(1, 100),
        ));
        $stopTimeRows = array_map(
            static fn (int $i): array => ['T' . $i, 'S1', 1],
            range(1, 100),
        );
        $stopTimeRows[] = ['T1', 'E2', 2];
        $stopTimeRows = array_merge(
            $stopTimeRows,
            array_map(static fn (int $i): array => ['T' . $i, 'E1', 2], range(2, 100)),
        );

        $source = $this->feed([
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3",
            'trips.txt' => $trips,
            'stop_times.txt' => $this->stopTimes($stopTimeRows),
            'stops.txt' => $this->stops(['S1' => 'Main', 'E1' => 'End', 'E2' => 'End']),
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\n"
                . "S1,1,1,1,1,1,0,0,20260101,20261231",
            'calendar_dates.txt' => "service_id,date,exception_type\n",
        ]);

        $filtered = $this->filter($source, ['R1']);

        self::assertSame($source, $filtered, 'both platforms share the regular terminus name');
        self::assertCount(100, $this->readCsv($filtered, 'trips.txt'));
    }

    #[Test]
    public function itComparesTheEndpointShareWithinTheTripsService(): void
    {
        $source = $this->feed([
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3",
            'trips.txt' => $this->rows(['route_id', 'service_id', 'trip_id'], [
                ...array_map(static fn (int $i): array => ['R1', 'S1', 'T' . $i], range(1, 100)),
                ['R1', 'S2', 'T200'],
            ]),
            'stop_times.txt' => $this->stopTimes([
                ...$this->regularStopTimes(1, 100),
                ['T200', 'S0', 1],
                ['T200', 'S2', 2],
            ]),
            'stops.txt' => $this->stops(['S0' => 'Sunday origin', 'S1' => 'Main', 'S2' => 'End']),
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\n"
                . "S1,1,1,1,1,1,0,0,20260101,20261231\nS2,0,0,0,0,0,0,1,20260101,20261231",
            'calendar_dates.txt' => "service_id,date,exception_type\n",
        ]);

        $filtered = $this->filter($source, ['R1']);

        self::assertSame($source, $filtered, 'the only Sunday trip keeps its own origin');
        self::assertCount(101, $this->readCsv($filtered, 'trips.txt'));
    }

    #[Test]
    public function itHonoursAConfiguredEndpointShare(): void
    {
        $source = $this->feed([
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3",
            'trips.txt' => $this->rows(['route_id', 'service_id', 'trip_id'], [
                ...array_map(static fn (int $i): array => ['R1', 'S1', 'T' . $i], range(1, 60)),
                ...array_map(static fn (int $i): array => ['R1', 'S1', 'X' . $i], range(1, 40)),
            ]),
            'stop_times.txt' => $this->stopTimes([
                ...array_map(static fn (int $i): array => ['T' . $i, 'S1', 1], range(1, 60)),
                ...array_map(static fn (int $i): array => ['T' . $i, 'S2', 2], range(1, 60)),
                ...array_map(static fn (int $i): array => ['X' . $i, 'S0', 1], range(1, 40)),
                ...array_map(static fn (int $i): array => ['X' . $i, 'S2', 2], range(1, 40)),
            ]),
            'stops.txt' => $this->stops(['S0' => 'Rare origin', 'S1' => 'Main', 'S2' => 'End']),
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\n"
                . "S1,1,1,1,1,1,0,0,20260101,20261231",
            'calendar_dates.txt' => "service_id,date,exception_type\n",
        ]);

        self::assertSame($source, $this->filter($source, ['R1']), '40% is above the default');

        $strict = new GtfsTripVariantFilter(new Filesystem(), '0.5');
        $filtered = $strict->filter($source, ['R1']);
        $this->outputs[] = $filtered;
        self::assertCount(60, $this->readCsv($filtered, 'trips.txt'));
    }

    #[Test]
    public function itLeavesUnselectedRoutesAlone(): void
    {
        $source = $this->feed($this->rareEndpointFeed());

        $filtered = $this->filter($source, ['R1']);

        $trips = $this->readCsv($filtered, 'trips.txt');
        self::assertContains('U1', array_column($trips, 'trip_id'));
        self::assertNotContains('X1', array_column($trips, 'trip_id'));
    }

    /**
     * @param list<string> $routeIds
     */
    private function filter(string $source, array $routeIds): string
    {
        $filtered = $this->filter->filter($source, $routeIds);
        if ($filtered !== $source) {
            $this->outputs[] = $filtered;
        }

        return $filtered;
    }

    /** @return array<string, string> */
    private function rareEndpointFeed(): array
    {
        $r1Trips = array_map(static fn (int $i): array => ['R1', 'S1', 'T' . $i], range(1, 98));
        $r1Trips[] = ['R1', 'S1', 'X1'];
        $r2Trips = array_map(static fn (int $i): array => ['R2', 'S1', 'U' . $i], range(1, 100));

        $r1StopTimes = $this->regularStopTimes(1, 98);
        $r1StopTimes[] = ['X1', 'S0', 1];
        $r1StopTimes[] = ['X1', 'S2', 2];
        $r2StopTimes = array_map(static fn (int $i): array => ['U' . $i, 'S0', 1], range(1, 100));
        $r2StopTimes = array_merge($r2StopTimes, array_map(
            static fn (int $i): array => ['U' . $i, 'S3', 2],
            range(1, 100),
        ));

        return [
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3\nR2,2,Two,3",
            'trips.txt' => $this->rows(['route_id', 'service_id', 'trip_id'], [...$r1Trips, ...$r2Trips]),
            'stop_times.txt' => $this->stopTimes([...$r1StopTimes, ...$r2StopTimes]),
            'stops.txt' => $this->stops([
                'S0' => 'Other terminus',
                'S1' => 'Main',
                'S2' => 'End',
                'S3' => 'Other end',
            ]),
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\n"
                . "S1,1,1,1,1,1,0,0,20260101,20261231",
            'calendar_dates.txt' => "service_id,date,exception_type\n",
        ];
    }

    /**
     * @param list<array{0: string, 1: string, 2: int}> $rows
     */
    private function stopTimes(array $rows): string
    {
        $lines = ["trip_id,stop_id,stop_sequence"];
        foreach ($rows as [$trip, $stop, $sequence]) {
            $lines[] = "{$trip},{$stop},{$sequence}";
        }

        return implode("\n", $lines);
    }

    /** @return list<array{0: string, 1: string, 2: int}> */
    private function regularStopTimes(int $from, int $to): array
    {
        $rows = [];
        foreach (range($from, $to) as $i) {
            $rows[] = ['T' . $i, 'S1', 1];
            $rows[] = ['T' . $i, 'S2', 2];
        }

        return $rows;
    }

    /** @param array<string, string> $names */
    private function stops(array $names): string
    {
        $lines = ['stop_id,stop_name'];
        foreach ($names as $id => $name) {
            $lines[] = "{$id},{$name}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     */
    private function rows(array $header, array $rows): string
    {
        $lines = [implode(',', $header)];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return implode("\n", $lines);
    }

    /** @param array<string, string> $files */
    private function feed(array $files): string
    {
        $path = $this->storageDir . '/feed-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the GTFS test zip.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    /** @return list<array<string, string>> */
    private function readCsv(string $path, string $name): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $stream = $zip->getStream($name);
        self::assertIsResource($stream);
        $header = fgetcsv($stream, null, ',', '"', '');
        self::assertIsArray($header);
        $header = array_map(static fn (?string $column): string => (string) $column, $header);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            /** @var array<string, string> $combined */
            $combined = array_combine($header, array_map(static fn (?string $value): string => (string) $value, $row));
            $rows[] = $combined;
        }
        fclose($stream);
        $zip->close();

        return $rows;
    }
}
