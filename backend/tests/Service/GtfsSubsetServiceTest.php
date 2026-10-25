<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\GtfsSource;
use App\Exception\FunctionalException;
use App\Repository\GtfsSourceRepository;
use App\Service\GtfsService;
use App\Service\GtfsSubsetService;
use App\Service\OutboundHttpFetcher;
use App\Service\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;

final class GtfsSubsetServiceTest extends TestCase
{
    private string $storageDir;
    private GtfsSubsetService $subsets;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/gtfs-subset-test-' . bin2hex(random_bytes(4));
        mkdir($this->storageDir, 0o755, true);
        $gtfs = new GtfsService(
            $this->createStub(GtfsSourceRepository::class),
            $this->storageDir,
            new OutboundHttpFetcher(new MockHttpClient(), new OutboundUrlGuard(), new NullLogger()),
            new NullLogger(),
            new OutboundUrlGuard(),
            new Filesystem(),
        );
        $this->subsets = new GtfsSubsetService($gtfs, new Filesystem(), new NullLogger());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->storageDir);
        parent::tearDown();
    }

    #[Test]
    public function itFiltersTripsStopTimesShapesAndStopsToTheSelectedRoutes(): void
    {
        $source = $this->source($this->feedFiles());

        $subsetPath = $this->subsets->build($source, ['R1']);

        self::assertFileExists($subsetPath);
        self::assertSame($subsetPath, $this->subsets->build($source, ['R1']));

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($subsetPath));

        $routes = $this->readCsv($zip, 'routes.txt');
        self::assertSame(['R1'], array_column($routes, 'route_id'));

        $trips = $this->readCsv($zip, 'trips.txt');
        self::assertSame(['T1'], array_column($trips, 'trip_id'));

        $stopTimes = $this->readCsv($zip, 'stop_times.txt');
        self::assertSame(['T1', 'T1'], array_column($stopTimes, 'trip_id'));
        self::assertSame(['S1', 'S2'], array_column($stopTimes, 'stop_id'));

        $shapes = $this->readCsv($zip, 'shapes.txt');
        self::assertSame(['SH1'], array_values(array_unique(array_column($shapes, 'shape_id'))));

        $stops = $this->readCsv($zip, 'stops.txt');
        $stopIds = array_column($stops, 'stop_id');
        self::assertContains('S1', $stopIds);
        self::assertContains('S2', $stopIds);
        self::assertContains('P1', $stopIds, 'the parent station is kept');
        self::assertNotContains('S3', $stopIds, 'the other network stop is dropped');

        $calendar = $this->readCsv($zip, 'calendar.txt');
        self::assertSame(['S1'], array_column($calendar, 'service_id'));

        $zip->close();
    }

    #[Test]
    public function itFollowsAMultiLevelParentStationClosure(): void
    {
        $files = $this->feedFiles();
        $files['stops.txt'] = "stop_id,stop_name,parent_station\nP2,Parent2,\nP1,Parent1,P2\nS1,Stop1,P1\nS2,Stop2,\nS3,Stop3,";
        $files['stop_times.txt'] = "trip_id,stop_id,stop_sequence\nT1,S1,1\nT2,S3,1";
        $source = $this->source($files);

        $subsetPath = $this->subsets->build($source, ['R1']);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($subsetPath));
        $ids = array_column($this->readCsv($zip, 'stops.txt'), 'stop_id');
        self::assertContains('S1', $ids);
        self::assertContains('P1', $ids);
        self::assertContains('P2', $ids);
        self::assertNotContains('S3', $ids);
        $zip->close();
    }

    #[Test]
    public function itAcceptsAFeedWithOnlyCalendarDates(): void
    {
        $files = $this->feedFiles();
        unset($files['calendar.txt']);
        $source = $this->source($files);

        $subsetPath = $this->subsets->build($source, ['R1']);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($subsetPath));
        self::assertFalse($zip->locateName('calendar.txt') !== false);
        self::assertSame([], $this->readCsv($zip, 'calendar_dates.txt'));
        $zip->close();
    }

    #[Test]
    public function itRejectsMoreThanOneHundredRoutes(): void
    {
        $source = $this->source($this->feedFiles());

        try {
            $this->subsets->build($source, array_map(static fn (int $i): string => 'R' . $i, range(1, 101)));
            self::fail('Expected the route selection cap.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.selection_too_large', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsAnEmptySelection(): void
    {
        $source = $this->source($this->feedFiles());

        try {
            $this->subsets->build($source, []);
            self::fail('Expected the empty selection error.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.selection_empty', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsASelectionWithNoMatchingRoutes(): void
    {
        $source = $this->source($this->feedFiles());

        try {
            $this->subsets->build($source, ['MISSING']);
            self::fail('Expected the no-matching-route error.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.selection_empty', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsASubsetThatExceedsTheSubsetSizeCap(): void
    {
        $source = $this->source([
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,3",
            'trips.txt' => "route_id,service_id,trip_id,shape_id\nR1,S1,T1,SH1",
            'shapes.txt' => "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\nSH1,46.5,6.6,1",
            'stops.txt' => "stop_id,stop_name,parent_station\nS1,Stop1,",
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nS1,1,1,1,1,1,0,0,20260101,20261231",
            'calendar_dates.txt' => "service_id,date,exception_type\n",
        ], $this->bigStopTimesFile(257, 'T1'));

        try {
            $this->subsets->build($source, ['R1']);
            self::fail('Expected the subset size cap.');
        } catch (FunctionalException $e) {
            self::assertSame('gtfs.subset_too_large', $e->getErrorKey());
        }
    }

    /**
     * @param array<string, string> $files
     * @param string|null $stopTimesFile
     */
    private function source(array $files, ?string $stopTimesFile = null): GtfsSource
    {
        $path = $this->writeZip($files, $stopTimesFile);

        return new GtfsSource('Feed', $path);
    }

    /** @return array<string, string> */
    private function feedFiles(): array
    {
        return [
            'agency.txt' => "agency_id,agency_name\nA1,Agency",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\nR1,1,One,0\nR2,2,Two,3",
            'trips.txt' => "route_id,service_id,trip_id,shape_id\nR1,S1,T1,SH1\nR2,S1,T2,SH2",
            'stop_times.txt' => "trip_id,stop_id,stop_sequence\nT1,S1,1\nT1,S2,2\nT2,S3,1",
            'shapes.txt' => "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\nSH1,46.5,6.6,1\nSH2,46.6,6.7,1",
            'stops.txt' => "stop_id,stop_name,parent_station\nP1,Parent,\nS1,Stop1,P1\nS2,Stop2,\nS3,Stop3,",
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nS1,1,1,1,1,1,0,0,20260101,20261231",
            'calendar_dates.txt' => "service_id,date,exception_type\n",
            'feed_info.txt' => "feed_publisher_name,feed_publisher_url,feed_lang\nTest,https://example.com,en",
        ];
    }

    /**
     * @param array<string, string> $files
     */
    private function writeZip(array $files, ?string $stopTimesFile = null): string
    {
        $path = $this->storageDir . '/fixture-' . bin2hex(random_bytes(4)) . '.zip';
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0o755, true) && !is_dir($this->storageDir)) {
            throw new \RuntimeException('Could not create the GTFS test storage.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the GTFS test zip.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        if ($stopTimesFile !== null) {
            $zip->addFile($stopTimesFile, 'stop_times.txt');
        }
        $zip->close();
        if ($stopTimesFile !== null) {
            @unlink($stopTimesFile);
        }

        return $path;
    }

    private function bigStopTimesFile(int $sizeMiB, string $tripId): string
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
            $line = $tripId . ',S' . ($i % 500) . ",1\n";
            fwrite($handle, $line);
            $bytes += strlen($line);
            ++$i;
        }
        fclose($handle);

        return $path;
    }

    /** @return list<array<string, string>> */
    private function readCsv(\ZipArchive $zip, string $name): array
    {
        $stream = $zip->getStream($name);
        self::assertIsResource($stream);
        $header = fgetcsv($stream, null, ',', '"', '');
        self::assertIsArray($header);
        $columns = array_map(static fn (string|null $h): string => (string) $h, $header);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $assoc = [];
            foreach ($columns as $index => $column) {
                $assoc[$column] = $row[$index] ?? '';
            }
            $rows[] = $assoc;
        }
        fclose($stream);

        return $rows;
    }
}
