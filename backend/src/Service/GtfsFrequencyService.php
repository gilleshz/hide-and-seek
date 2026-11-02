<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Trips per route on its busiest weekday, read from a feed's calendar and trips.
 *
 * Counts come from calendar.txt weekday flags; a service expressed only through
 * calendar_dates.txt additions is not counted. Feeds without calendar.txt report no
 * counts, so callers treat their frequencies as unknown.
 */
final readonly class GtfsFrequencyService
{
    /** @var list<string> */
    private const array WEEKDAY_COLUMNS = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    /**
     * @return array<string, int> route_id => busiest weekday trip count
     */
    public function tripsPerDay(\ZipArchive $zip): array
    {
        $serviceDays = $this->serviceWeekdays($zip);
        if ($serviceDays === []) {
            return [];
        }

        $stream = $zip->getStream('trips.txt');
        if (!is_resource($stream)) {
            return [];
        }

        try {
            $map = $this->headerMap($stream);
            $tripIndex = $map['trip_id'] ?? null;
            $routeIndex = $map['route_id'] ?? null;
            $serviceIndex = $map['service_id'] ?? null;
            if ($tripIndex === null || $routeIndex === null || $serviceIndex === null) {
                return [];
            }
            $weights = $this->tripWeights($zip);

            /** @var array<string, array<int, int>> $counts */
            $counts = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $routeId = trim((string) ($row[$routeIndex] ?? ''));
                if ($routeId === '') {
                    continue;
                }
                $weight = $weights[trim((string) ($row[$tripIndex] ?? ''))] ?? 1;
                foreach ($serviceDays[trim((string) ($row[$serviceIndex] ?? ''))] ?? [] as $day) {
                    $counts[$routeId][$day] = ($counts[$routeId][$day] ?? 0) + $weight;
                }
            }
        } finally {
            fclose($stream);
        }

        $perDay = [];
        foreach ($counts as $routeId => $byDay) {
            $perDay[$routeId] = $byDay === [] ? 0 : max($byDay);
        }

        return $perDay;
    }

    /**
     * @return array<string, list<int>> service_id => weekday indexes it runs on
     */
    private function serviceWeekdays(\ZipArchive $zip): array
    {
        if ($zip->locateName('calendar.txt') === false) {
            return [];
        }
        $stream = $zip->getStream('calendar.txt');
        if (!is_resource($stream)) {
            return [];
        }

        try {
            $map = $this->headerMap($stream);
            $serviceIndex = $map['service_id'] ?? null;
            if ($serviceIndex === null) {
                return [];
            }
            $dayColumns = [];
            foreach (self::WEEKDAY_COLUMNS as $day => $column) {
                if (isset($map[$column])) {
                    $dayColumns[$day] = $column;
                }
            }

            /** @var array<string, list<int>> $serviceDays */
            $serviceDays = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $serviceId = trim((string) ($row[$serviceIndex] ?? ''));
                if ($serviceId === '') {
                    continue;
                }
                $days = [];
                foreach ($dayColumns as $day => $column) {
                    if (($row[$map[$column]] ?? '') === '1') {
                        $days[] = $day;
                    }
                }
                $serviceDays[$serviceId] = $days;
            }

            return $serviceDays;
        } finally {
            fclose($stream);
        }
    }

    /**
     * A trip listed in frequencies.txt stands for one departure per headway instead of a single run.
     *
     * @return array<string, int> trip_id => departures per service day
     */
    private function tripWeights(\ZipArchive $zip): array
    {
        if ($zip->locateName('frequencies.txt') === false) {
            return [];
        }
        $stream = $zip->getStream('frequencies.txt');
        if (!is_resource($stream)) {
            return [];
        }

        try {
            $map = $this->headerMap($stream);
            $tripIndex = $map['trip_id'] ?? null;
            $startIndex = $map['start_time'] ?? null;
            $endIndex = $map['end_time'] ?? null;
            $headwayIndex = $map['headway_secs'] ?? null;
            if ($tripIndex === null || $startIndex === null || $endIndex === null || $headwayIndex === null) {
                return [];
            }

            /** @var array<string, int> $weights */
            $weights = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $tripId = trim((string) ($row[$tripIndex] ?? ''));
                $start = self::secondsOfDay((string) ($row[$startIndex] ?? ''));
                $end = self::secondsOfDay((string) ($row[$endIndex] ?? ''));
                $headway = (int) ($row[$headwayIndex] ?? 0);
                if ($tripId === '' || $start === null || $end === null || $headway <= 0 || $end <= $start) {
                    continue;
                }
                $weights[$tripId] = ($weights[$tripId] ?? 0) + (int) ceil(($end - $start) / $headway);
            }

            return $weights;
        } finally {
            fclose($stream);
        }
    }

    private static function secondsOfDay(string $value): ?int
    {
        $parts = explode(':', trim($value));
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
            return null;
        }

        return (int) $parts[0] * 3600 + (int) $parts[1] * 60 + (int) $parts[2];
    }

    /**
     * @param resource $stream
     *
     * @return array<string, int>
     */
    private function headerMap($stream): array
    {
        $header = fgetcsv($stream, null, ',', '"', '');
        if ($header === false) {
            return [];
        }

        return array_flip(array_map(static fn (string|null $column): string => (string) $column, $header));
    }
}
