<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\FunctionalException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Drops short-turn and interlining trips whose first or last stop their route almost never serves.
 *
 * A real terminus is used by most of a route's trips; a rare endpoint is a partial working borrowed
 * from another line (the Metz MA trips starting at Maternite, the Strasbourg C trips starting at
 * Place d'Islande), and it adds a branch the published line map does not have.
 */
final readonly class GtfsTripVariantFilter
{
    // Real termini are served by most trips of a service; a rarer first/last stop is a short-turn.
    private const float DEFAULT_MIN_ENDPOINT_TRIP_SHARE = 0.03;

    public function __construct(
        private Filesystem $filesystem,
        #[Autowire('%env(default::GTFS_VARIANT_MIN_ENDPOINT_SHARE)%')]
        private ?string $minEndpointTripShare = null,
    ) {
    }

    /**
     * @param list<string> $routeIds
     * @return string the filtered feed, or the input path when nothing was dropped
     */
    public function filter(string $zipPath, array $routeIds): string
    {
        if ($routeIds === []) {
            return $zipPath;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new FunctionalException(
                message: 'Failed to open GTFS archive.',
                errorKey: 'gtfs.archive_open_failed',
            );
        }

        try {
            $dropped = $this->droppedTrips(
                $zip,
                $this->selectedTrips($zip, $routeIds),
                $this->stopNames($zip),
            );
        } finally {
            $zip->close();
        }

        return $dropped === [] ? $zipPath : $this->writeFiltered($zipPath, $dropped);
    }

    /**
     * @param list<string> $routeIds
     * @return array<string, array{route: string, service: string}> trip id to route and service
     */
    private function selectedTrips(\ZipArchive $zip, array $routeIds): array
    {
        $wanted = array_fill_keys($routeIds, true);
        $in = $this->openEntry($zip, 'trips.txt');
        try {
            $map = $this->headerMap($in, 'trips.txt');
            $tripIndex = $this->column($map, 'trip_id', 'trips.txt');
            $routeIndex = $this->column($map, 'route_id', 'trips.txt');
            $serviceIndex = $map['service_id'] ?? null;

            $trips = [];
            while (($row = fgetcsv($in, null, ',', '"', '')) !== false) {
                $tripId = trim((string) ($row[$tripIndex] ?? ''));
                $routeId = trim((string) ($row[$routeIndex] ?? ''));
                if ($tripId !== '' && isset($wanted[$routeId])) {
                    $trips[$tripId] = [
                        'route' => $routeId,
                        'service' => $serviceIndex !== null ? trim((string) ($row[$serviceIndex] ?? '')) : '',
                    ];
                }
            }

            return $trips;
        } finally {
            fclose($in);
        }
    }

    /** @return array<string, string> stop id to normalized name */
    private function stopNames(\ZipArchive $zip): array
    {
        $in = $zip->getStream('stops.txt');
        if (!is_resource($in)) {
            return [];
        }

        try {
            $header = fgetcsv($in, null, ',', '"', '');
            if ($header === false) {
                return [];
            }
            $map = $this->mapHeader($header);
            $idIndex = $map['stop_id'] ?? null;
            $nameIndex = $map['stop_name'] ?? null;
            if ($idIndex === null) {
                return [];
            }

            $names = [];
            while (($row = fgetcsv($in, null, ',', '"', '')) !== false) {
                $id = trim((string) ($row[$idIndex] ?? ''));
                if ($id === '') {
                    continue;
                }
                $name = $nameIndex !== null ? trim((string) ($row[$nameIndex] ?? '')) : '';
                $names[$id] = $this->normalizeName($name !== '' ? $name : $id);
            }

            return $names;
        } finally {
            fclose($in);
        }
    }

    /**
     * @param array<string, array{route: string, service: string}> $trips
     * @param array<string, string> $stopNames
     * @return array<string, true>
     */
    private function droppedTrips(\ZipArchive $zip, array $trips, array $stopNames): array
    {
        $scan = $this->scanStopTimes($zip, $trips, $stopNames);
        $totals = [];
        foreach ($trips as $trip) {
            $route = $trip['route'];
            $service = $trip['service'];
            $totals[$route][$service] = ($totals[$route][$service] ?? 0) + 1;
        }

        $dropped = [];
        foreach ($scan['endpoints'] as $tripId => $names) {
            $trip = $trips[$tripId];
            $total = $totals[$trip['route']][$trip['service']];
            foreach (array_unique($names) as $name) {
                $rows = $scan['stopRows'][$trip['route']][$trip['service']][$name] ?? 0;
                if ($rows / $total < $this->minEndpointTripShare()) {
                    $dropped[$tripId] = true;
                    break;
                }
            }
        }

        return $dropped;
    }

    private function minEndpointTripShare(): float
    {
        $configured = (float) ($this->minEndpointTripShare ?? '');

        return $configured > 0.0 ? $configured : self::DEFAULT_MIN_ENDPOINT_TRIP_SHARE;
    }

    /**
     * @param array<string, array{route: string, service: string}> $trips
     * @param array<string, string> $stopNames
     * @return array{
     *     endpoints: array<string, array{0: string, 1: string}>,
     *     stopRows: array<string, array<string, array<string, int>>>
     * }
     */
    private function scanStopTimes(\ZipArchive $zip, array $trips, array $stopNames): array
    {
        $in = $this->openEntry($zip, 'stop_times.txt');
        try {
            $map = $this->headerMap($in, 'stop_times.txt');
            $tripIndex = $this->column($map, 'trip_id', 'stop_times.txt');
            $stopIndex = $this->column($map, 'stop_id', 'stop_times.txt');
            $sequenceIndex = $this->column($map, 'stop_sequence', 'stop_times.txt');

            $first = [];
            $last = [];
            $firstSequence = [];
            $lastSequence = [];
            $stopRows = [];
            while (($row = fgetcsv($in, null, ',', '"', '')) !== false) {
                $tripId = trim((string) ($row[$tripIndex] ?? ''));
                if (!isset($trips[$tripId])) {
                    continue;
                }
                $trip = $trips[$tripId];
                $sequence = (int) ($row[$sequenceIndex] ?? 0);
                $stopId = trim((string) ($row[$stopIndex] ?? ''));
                $name = $stopNames[$stopId] ?? $stopId;
                $route = $trip['route'];
                $service = $trip['service'];
                $stopRows[$route][$service][$name] = ($stopRows[$route][$service][$name] ?? 0) + 1;
                if (!isset($firstSequence[$tripId]) || $sequence < $firstSequence[$tripId]) {
                    $firstSequence[$tripId] = $sequence;
                    $first[$tripId] = $name;
                }
                if (!isset($lastSequence[$tripId]) || $sequence > $lastSequence[$tripId]) {
                    $lastSequence[$tripId] = $sequence;
                    $last[$tripId] = $name;
                }
            }

            $endpoints = [];
            foreach ($first as $tripId => $name) {
                $endpoints[$tripId] = [$name, $last[$tripId] ?? $name];
            }

            return ['endpoints' => $endpoints, 'stopRows' => $stopRows];
        } finally {
            fclose($in);
        }
    }

    /** @param array<string, true> $dropped */
    private function writeFiltered(string $zipPath, array $dropped): string
    {
        $tmpDir = sys_get_temp_dir() . '/gtfs-variants-' . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($tmpDir);
        $output = sys_get_temp_dir() . '/gtfs-variants-' . bin2hex(random_bytes(8)) . '.zip';

        try {
            $input = new \ZipArchive();
            if ($input->open($zipPath) !== true) {
                throw new FunctionalException(
                    message: 'Failed to open GTFS archive.',
                    errorKey: 'gtfs.archive_open_failed',
                );
            }
            try {
                $entries = $this->extractEntries($input, $tmpDir, $dropped);
            } finally {
                $input->close();
            }
            $this->writeArchive($output, $entries);
        } catch (\Throwable $e) {
            @unlink($output);
            throw $e;
        } finally {
            $this->filesystem->remove($tmpDir);
        }

        return $output;
    }

    /**
     * @param array<string, true> $dropped
     * @return array<string, string> temporary path to entry name
     */
    private function extractEntries(\ZipArchive $input, string $tmpDir, array $dropped): array
    {
        $entries = [];
        for ($i = 0; $i < $input->numFiles; ++$i) {
            $entry = $input->statIndex($i);
            if ($entry === false || str_ends_with((string) $entry['name'], '/')) {
                continue;
            }
            $name = (string) $entry['name'];
            $path = $tmpDir . '/' . $i;
            if ($name === 'trips.txt' || $name === 'stop_times.txt') {
                $this->filterCsv($input, $name, $path, $dropped);
            } else {
                $this->copyEntry($input, $name, $path);
            }
            $entries[$path] = $name;
        }

        return $entries;
    }

    /** @param array<string, string> $entries */
    private function writeArchive(string $output, array $entries): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($output, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the filtered GTFS archive.');
        }

        try {
            foreach ($entries as $path => $name) {
                if (!$zip->addFile($path, $name)) {
                    throw new \RuntimeException("Could not write {$name} to the filtered GTFS archive.");
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, true> $dropped */
    private function filterCsv(\ZipArchive $zip, string $name, string $outputPath, array $dropped): void
    {
        $in = $this->openEntry($zip, $name);
        $out = fopen($outputPath, 'wb');
        if ($out === false) {
            fclose($in);

            throw new \RuntimeException("Could not write the filtered {$name}.");
        }

        try {
            $header = fgetcsv($in, null, ',', '"', '');
            if ($header === false) {
                throw new FunctionalException(
                    message: "Invalid GTFS archive: {$name} is empty.",
                    errorKey: 'gtfs.invalid_archive',
                );
            }
            $tripIndex = $this->column($this->mapHeader($header), 'trip_id', $name);
            fputcsv($out, $header, ',', '"', '');

            while (($row = fgetcsv($in, null, ',', '"', '')) !== false) {
                $tripId = trim((string) ($row[$tripIndex] ?? ''));
                if (!isset($dropped[$tripId])) {
                    fputcsv($out, $row, ',', '"', '');
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function copyEntry(\ZipArchive $zip, string $name, string $outputPath): void
    {
        $in = $zip->getStream($name);
        if (!is_resource($in)) {
            throw new FunctionalException(
                message: "Invalid GTFS archive: {$name} is missing.",
                errorKey: 'gtfs.invalid_archive',
            );
        }
        $out = fopen($outputPath, 'wb');
        if ($out === false) {
            fclose($in);

            throw new \RuntimeException("Could not copy {$name} from the GTFS archive.");
        }

        try {
            if (stream_copy_to_stream($in, $out) === false) {
                throw new \RuntimeException("Could not copy {$name} from the GTFS archive.");
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** @return resource */
    private function openEntry(\ZipArchive $zip, string $name)
    {
        $stream = $zip->getStream($name);
        if (!is_resource($stream)) {
            throw new FunctionalException(
                message: "Invalid GTFS archive: {$name} is missing.",
                errorKey: 'gtfs.invalid_archive',
            );
        }

        return $stream;
    }

    /** @param resource $stream
     *  @return array<string, int> */
    private function headerMap($stream, string $name): array
    {
        $header = fgetcsv($stream, null, ',', '"', '');
        if ($header === false) {
            throw new FunctionalException(
                message: "Invalid GTFS archive: {$name} is empty.",
                errorKey: 'gtfs.invalid_archive',
            );
        }

        return $this->mapHeader($header);
    }

    /**
     * @param array<int, string|null> $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($this->normalizeHeader($header) as $index => $column) {
            if (!isset($map[$column])) {
                $map[$column] = $index;
            }
        }

        return $map;
    }

    /** @param array<string, int> $map */
    private function column(array $map, string $column, string $name): int
    {
        $index = $map[$column] ?? null;
        if ($index === null) {
            throw new FunctionalException(
                message: "Invalid GTFS archive: {$name} is missing the {$column} column.",
                errorKey: 'gtfs.invalid_archive',
            );
        }

        return $index;
    }

    /**
     * @param array<int, string|null> $header
     * @return list<string>
     */
    private function normalizeHeader(array $header): array
    {
        $columns = array_values(array_map(static fn (?string $column): string => (string) $column, $header));
        if ($columns !== [] && str_starts_with($columns[0], "\xEF\xBB\xBF")) {
            $columns[0] = substr($columns[0], 3);
        }

        return $columns;
    }

    private function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $collapsed = preg_replace('/\s+/u', ' ', $normalized);

        return $collapsed ?? $normalized;
    }
}
