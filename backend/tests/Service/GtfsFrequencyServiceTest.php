<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GtfsFrequencyService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GtfsFrequencyServiceTest extends TestCase
{
    private GtfsFrequencyService $service;

    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $this->service = new GtfsFrequencyService();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];

        parent::tearDown();
    }

    #[Test]
    public function itCountsTripsOnTheBusiestWeekdayPerRoute(): void
    {
        $zip = $this->openZip($this->writeZip([
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,"
                . "start_date,end_date\n"
                . "WEEKDAY,1,1,1,1,1,0,0,20260101,20261231\n"
                . "WEEKEND,0,0,0,0,0,1,1,20260101,20261231\n",
            'trips.txt' => "route_id,service_id,trip_id\n"
                . "R1,WEEKDAY,T1\n"
                . "R1,WEEKDAY,T2\n"
                . "R1,WEEKEND,T3\n"
                . "R2,WEEKDAY,T4\n"
                . "R2,WEEKEND,T5\n"
                . "R2,WEEKEND,T6\n",
        ]));

        $tripsPerDay = $this->service->tripsPerDay($zip);
        $zip->close();

        self::assertSame(['R1' => 2, 'R2' => 2], $tripsPerDay);
    }

    #[Test]
    public function itExpandsFrequencyBasedTrips(): void
    {
        $zip = $this->openZip($this->writeZip([
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,"
                . "start_date,end_date\n"
                . "WEEKDAY,1,1,1,1,1,0,0,20260101,20261231\n",
            'trips.txt' => "route_id,service_id,trip_id\n"
                . "R1,WEEKDAY,T1\n"
                . "R1,WEEKDAY,T2\n"
                . "R1,WEEKDAY,T3\n",
            'frequencies.txt' => "trip_id,start_time,end_time,headway_secs,exact_times\n"
                . "T1,08:00:00,10:00:00,600,1\n"
                . "T3,08:00:00,10:00:00,700,1\n",
        ]));

        $tripsPerDay = $this->service->tripsPerDay($zip);
        $zip->close();

        self::assertSame(['R1' => 24], $tripsPerDay);
    }

    #[Test]
    public function itReportsNothingWithoutACalendar(): void
    {
        $zip = $this->openZip($this->writeZip([
            'trips.txt' => "route_id,service_id,trip_id\nR1,S1,T1\n",
        ]));

        $tripsPerDay = $this->service->tripsPerDay($zip);
        $zip->close();

        self::assertSame([], $tripsPerDay);
    }

    /**
     * @param array<string, string> $files
     */
    private function writeZip(array $files): string
    {
        $path = sys_get_temp_dir() . '/gtfs-frequency-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $this->paths[] = $path;

        return $path;
    }

    private function openZip(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));

        return $zip;
    }
}
