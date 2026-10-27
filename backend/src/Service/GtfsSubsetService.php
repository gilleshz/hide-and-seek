<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GtfsSource;
use App\ErrorKey;
use App\Exception\FunctionalException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Builds a route-filtered GTFS archive from a large source.
 *
 * A national source is downloaded and cached whole, but only the selected routes
 * (at most 100) are extracted into the subset that the tile and timetable
 * pipelines process. Everything is streamed: the source stop_times can exceed
 * 1 GB uncompressed and must never be loaded into memory at once.
 */
final readonly class GtfsSubsetService
{
    private const int MAX_SMALL_FILE_BYTES = 16 * 1024 * 1024;
    private const int MAX_PARENT_DEPTH = 10;

    public function __construct(
        private GtfsService $gtfs,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $routeIds GTFS route_id values selected by the host
     *
     * @return string the cached subset zip path
     */
    public function build(GtfsSource $source, array $routeIds): string
    {
        $routeIds = array_values(array_unique(array_filter(
            array_map(static fn (string $id): string => trim($id), $routeIds),
            static fn (string $id): bool => $id !== '',
        )));

        if ($routeIds === []) {
            throw new FunctionalException(
                message: 'Select at least one GTFS route from this feed.',
                errorKey: ErrorKey::GTFS_SELECTION_EMPTY,
            );
        }
        if (count($routeIds) > GtfsService::MAX_SELECTED_ROUTES) {
            throw new FunctionalException(
                message: 'Select at most ' . GtfsService::MAX_SELECTED_ROUTES . ' GTFS routes.',
                errorKey: ErrorKey::GTFS_SELECTION_TOO_LARGE,
            );
        }

        $sourcePath = $source->getFilePath();
        if (!is_file($sourcePath)) {
            throw new FunctionalException(
                message: 'The GTFS source file is missing.',
                errorKey: 'gtfs.archive_open_failed',
            );
        }

        sort($routeIds);
        $cacheDir = dirname($sourcePath) . '/subsets/' . $source->getUuid();
        $this->filesystem->mkdir($cacheDir);
        $hash = hash('sha256', implode("\n", $routeIds));
        $subsetPath = $cacheDir . '/' . $hash . '.zip';

        if (is_file($subsetPath)) {
            return $subsetPath;
        }

        $lock = fopen($cacheDir . '/.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not open the GTFS subset lock.');
        }

        try {
            if (flock($lock, LOCK_EX) === false) {
                throw new \RuntimeException('Could not lock the GTFS subset.');
            }
            if (is_file($subsetPath)) {
                return $subsetPath;
            }

            $started = microtime(true);
            $this->buildInto($sourcePath, $routeIds, $subsetPath);
            $this->logger->info('GTFS subset built for source {source}: {routes} routes, {bytes} bytes in {seconds}s.', [
                'source' => $source->getUuid(),
                'routes' => count($routeIds),
                'bytes' => (int) filesize($subsetPath),
                'seconds' => round(microtime(true) - $started, 1),
            ]);

            return $subsetPath;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Stop coordinates of a feed or of the route-filtered subset built for it.
     *
     * @return list<array{lat: float, lon: float}>
     */
    public function stopCoordinates(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new FunctionalException(
                message: 'Failed to open GTFS archive.',
                errorKey: 'gtfs.archive_open_failed',
            );
        }

        try {
            $in = $zip->getStream('stops.txt');
            if (!is_resource($in)) {
                throw new FunctionalException(
                    message: 'The GTFS source is missing stops.txt.',
                    errorKey: 'gtfs.invalid_archive',
                );
            }

            try {
                $header = fgetcsv($in, null, ',', '"', '');
                if ($header === false) {
                    throw new FunctionalException(
                        message: 'The GTFS source has an empty stops.txt.',
                        errorKey: 'gtfs.invalid_archive',
                    );
                }
                $map = array_flip(array_map(static fn (string|null $h): string => (string) $h, $header));
                $latIndex = $map['stop_lat'] ?? null;
                $lonIndex = $map['stop_lon'] ?? null;
                if ($latIndex === null || $lonIndex === null) {
                    return [];
                }

                $points = [];
                while (($row = fgetcsv($in, null, ',', '"', '')) !== false) {
                    $lat = $row[$latIndex] ?? '';
                    $lon = $row[$lonIndex] ?? '';
                    if (!is_numeric($lat) || !is_numeric($lon)) {
                        continue;
                    }
                    $points[] = ['lat' => (float) $lat, 'lon' => (float) $lon];
                }

                return $points;
            } finally {
                fclose($in);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Shape polylines of a feed or of the route-filtered subset built for it.
     *
     * @return list<list<array{lat: float, lon: float}>>
     */
    public function shapeCoordinates(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new FunctionalException(
                message: 'Failed to open GTFS archive.',
                errorKey: 'gtfs.archive_open_failed',
            );
        }

        try {
            $shapes = [];
            if ($zip->locateName('shapes.txt') !== false) {
                $in = $zip->getStream('shapes.txt');
                if (is_resource($in)) {
                    try {
                        $shapes = $this->readShapeRows($in);
                    } finally {
                        fclose($in);
                    }
                }
            }

            return $shapes;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param resource $in
     * @return list<list<array{lat: float, lon: float}>>
     */
    private function readShapeRows($in): array
    {
        $header = fgetcsv($in, null, ',', '"', '');
        if ($header === false) {
            return [];
        }
        $map = array_flip(array_map(static fn (string|null $h): string => (string) $h, $header));
        $idIndex = $map['shape_id'] ?? null;
        $latIndex = $map['shape_pt_lat'] ?? null;
        $lonIndex = $map['shape_pt_lon'] ?? null;
        $seqIndex = $map['shape_pt_sequence'] ?? null;
        if ($idIndex === null || $latIndex === null || $lonIndex === null || $seqIndex === null) {
            return [];
        }

        /** @var array<string, array<int, array{lat: float, lon: float}>> $byShape */
        $byShape = [];
        while (($row = fgetcsv($in, null, ',', '"', '')) !== false) {
            $id = $row[$idIndex] ?? '';
            $lat = $row[$latIndex] ?? '';
            $lon = $row[$lonIndex] ?? '';
            $sequence = $row[$seqIndex] ?? '';
            if ($id === '' || !is_numeric($lat) || !is_numeric($lon) || !is_numeric($sequence)) {
                continue;
            }
            $byShape[$id][(int) $sequence] = ['lat' => (float) $lat, 'lon' => (float) $lon];
        }

        $shapes = [];
        foreach ($byShape as $points) {
            ksort($points);
            $shapes[] = array_values($points);
        }

        return $shapes;
    }

    /**
     * @param list<string> $routeIds
     */
    private function buildInto(string $sourcePath, array $routeIds, string $subsetPath): void
    {
        $tmpDir = sys_get_temp_dir() . '/gtfs-subset-' . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($tmpDir);
        $zip = new \ZipArchive();
        $opened = $zip->open($sourcePath);
        if ($opened !== true) {
            $this->filesystem->remove($tmpDir);

            throw new FunctionalException(
                message: 'Failed to open GTFS archive for subsetting.',
                errorKey: 'gtfs.archive_open_failed',
            );
        }

        $totalBytes = 0;
        $closed = false;

        try {
            $selectedRoutes = array_fill_keys($routeIds, true);
            $seenRoutes = [];
            $matchedRoutes = 0;
            $this->filterCsv(
                $zip,
                'routes.txt',
                $tmpDir . '/routes.txt',
                static function (array $row, array $map) use ($selectedRoutes, &$matchedRoutes, &$seenRoutes): bool {
                    $routeId = $row[$map['route_id']] ?? '';
                    if (!isset($selectedRoutes[$routeId]) || isset($seenRoutes[$routeId])) {
                        return false;
                    }
                    $seenRoutes[$routeId] = true;
                    ++$matchedRoutes;

                    return true;
                },
                $totalBytes,
            );

            if ($matchedRoutes === 0) {
                throw new FunctionalException(
                    message: 'Select at least one GTFS route from this feed.',
                    errorKey: ErrorKey::GTFS_SELECTION_EMPTY,
                );
            }

            $selectedTrips = [];
            $selectedShapes = [];
            $selectedServices = [];
            $this->filterCsv(
                $zip,
                'trips.txt',
                $tmpDir . '/trips.txt',
                static function (
                    array $row,
                    array $map
                ) use (
                    $selectedRoutes,
                    &$selectedTrips,
                    &$selectedShapes,
                    &$selectedServices,
                ): bool {
                    if (!isset($selectedRoutes[$row[$map['route_id']] ?? ''])) {
                        return false;
                    }
                    $selectedTrips[$row[$map['trip_id']] ?? ''] = true;
                    $serviceId = $row[$map['service_id']] ?? '';
                    if ($serviceId !== '') {
                        $selectedServices[$serviceId] = true;
                    }
                    $shapeId = isset($map['shape_id']) ? ($row[$map['shape_id']] ?? '') : '';
                    if ($shapeId !== '') {
                        $selectedShapes[$shapeId] = true;
                    }

                    return true;
                },
                $totalBytes,
            );

            if ($selectedTrips === []) {
                throw new FunctionalException(
                    message: 'The selected GTFS routes have no trips.',
                    errorKey: ErrorKey::GTFS_SELECTION_EMPTY,
                );
            }

            $selectedStops = [];
            $this->filterCsv(
                $zip,
                'stop_times.txt',
                $tmpDir . '/stop_times.txt',
                static function (array $row, array $map) use ($selectedTrips, &$selectedStops): bool {
                    if (!isset($selectedTrips[$row[$map['trip_id']] ?? ''])) {
                        return false;
                    }
                    $stopId = $row[$map['stop_id']] ?? '';
                    if ($stopId !== '') {
                        $selectedStops[$stopId] = true;
                    }

                    return true;
                },
                $totalBytes,
            );

            $this->filterCsv(
                $zip,
                'shapes.txt',
                $tmpDir . '/shapes.txt',
                static fn (array $row, array $map): bool => isset($selectedShapes[$row[$map['shape_id']] ?? '']),
                $totalBytes,
            );

            $this->writeStops($zip, $tmpDir . '/stops.txt', $selectedStops, $totalBytes);

            if ($zip->locateName('calendar.txt') !== false) {
                $this->filterCsv(
                    $zip,
                    'calendar.txt',
                    $tmpDir . '/calendar.txt',
                    static fn (array $row, array $map): bool => isset($selectedServices[$row[$map['service_id']] ?? '']),
                    $totalBytes,
                );
            }
            if ($zip->locateName('calendar_dates.txt') !== false) {
                $this->filterCsv(
                    $zip,
                    'calendar_dates.txt',
                    $tmpDir . '/calendar_dates.txt',
                    static fn (array $row, array $map): bool => isset($selectedServices[$row[$map['service_id']] ?? '']),
                    $totalBytes,
                );
            }

            $handled = [
                'routes.txt', 'trips.txt', 'stop_times.txt', 'shapes.txt', 'stops.txt',
                'calendar.txt', 'calendar_dates.txt', 'agency.txt', 'feed_info.txt',
            ];
            $this->copyEntry($zip, 'agency.txt', $tmpDir . '/agency.txt', $totalBytes);
            $this->copyEntry($zip, 'feed_info.txt', $tmpDir . '/feed_info.txt', $totalBytes);

            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entry = $zip->statIndex($i);
                if ($entry === false) {
                    continue;
                }
                $name = $entry['name'];
                if (in_array($name, $handled, true) || (int) $entry['size'] > self::MAX_SMALL_FILE_BYTES) {
                    continue;
                }
                $this->copyEntry($zip, $name, $tmpDir . '/' . $name, $totalBytes);
            }

            $zip->close();
            $closed = true;
            $this->writeSubsetZip($tmpDir, $subsetPath);
        } finally {
            if (!$closed) {
                @$zip->close();
            }
            $this->filesystem->remove($tmpDir);
        }
    }

    /**
     * @param callable(array<int, string|null>, array<string, int>): bool $keep
     */
    private function filterCsv(
        \ZipArchive $zip,
        string $name,
        string $outputPath,
        callable $keep,
        int &$totalBytes,
    ): void {
        $in = $zip->getStream($name);
        if (!is_resource($in)) {
            throw new FunctionalException(
                message: "The GTFS source is missing {$name}.",
                errorKey: 'gtfs.invalid_archive',
            );
        }
        $out = fopen($outputPath, 'wb');
        if ($out === false) {
            fclose($in);

            throw new \RuntimeException("Could not write the GTFS subset file {$name}.");
        }

        try {
            $header = fgetcsv($in, null, ',', '"', '');
            if ($header === false) {
                throw new FunctionalException(
                    message: "The GTFS source has an empty {$name}.",
                    errorKey: 'gtfs.invalid_archive',
                );
            }
            $map = array_flip(array_map(static fn (string|null $h): string => (string) $h, $header));
            fputcsv($out, $header, ',', '"', '');
            $fileBytes = (int) ftell($out);

            while (($row = fgetcsv($in, null, ',', '"', '')) !== false) {
                if (!$keep($row, $map)) {
                    continue;
                }
                fputcsv($out, $row, ',', '"', '');
                $fileBytes = (int) ftell($out);
                $this->assertSubsetSize($fileBytes, $totalBytes + $fileBytes);
            }

            $totalBytes += $fileBytes;
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * @param array<string, true> $selectedStops
     */
    private function writeStops(
        \ZipArchive $zip,
        string $outputPath,
        array $selectedStops,
        int &$totalBytes,
    ): void {
        $entry = $zip->statName('stops.txt');
        if ($entry === false) {
            throw new FunctionalException(
                message: 'The GTFS source is missing stops.txt.',
                errorKey: 'gtfs.invalid_archive',
            );
        }
        if ((int) $entry['size'] > GtfsService::SUBSET_MAX_ENTRY_BYTES) {
            throw new FunctionalException(
                message: 'The selected GTFS routes produce a timetable that is too large. Select fewer routes.',
                errorKey: ErrorKey::GTFS_SUBSET_TOO_LARGE,
            );
        }

        $wanted = $selectedStops;
        $header = null;
        $map = [];
        for ($pass = 0; $pass < self::MAX_PARENT_DEPTH; ++$pass) {
            $before = count($wanted);
            $in = $zip->getStream('stops.txt');
            if (!is_resource($in)) {
                throw new FunctionalException(
                    message: 'The GTFS source is missing stops.txt.',
                    errorKey: 'gtfs.invalid_archive',
                );
            }
            $first = true;
            while (($row = fgetcsv($in, null, ',', '"', '')) !== false) {
                if ($first) {
                    if ($header === null) {
                        $header = $row;
                        $map = array_flip(array_map(static fn (string|null $h): string => (string) $h, $header));
                    }
                    $first = false;
                    continue;
                }
                $stopIndex = $map['stop_id'] ?? null;
                $id = $stopIndex !== null ? ($row[$stopIndex] ?? '') : '';
                if ($id === '' || !isset($wanted[$id])) {
                    continue;
                }
                $parentIndex = $map['parent_station'] ?? null;
                $parent = $parentIndex !== null ? ($row[$parentIndex] ?? '') : '';
                if ($parent !== '' && !isset($wanted[$parent])) {
                    $wanted[$parent] = true;
                }
            }
            fclose($in);
            if (count($wanted) === $before) {
                break;
            }
        }

        if ($header === null) {
            throw new FunctionalException(
                message: 'The GTFS source has an empty stops.txt.',
                errorKey: 'gtfs.invalid_archive',
            );
        }

        $in = $zip->getStream('stops.txt');
        if (!is_resource($in)) {
            throw new FunctionalException(
                message: 'The GTFS source is missing stops.txt.',
                errorKey: 'gtfs.invalid_archive',
            );
        }
        $out = fopen($outputPath, 'wb');
        if ($out === false) {
            fclose($in);

            throw new \RuntimeException('Could not write the GTFS subset stops.txt.');
        }
        try {
            fputcsv($out, $header, ',', '"', '');
            $headerSeen = false;
            while (($row = fgetcsv($in, null, ',', '"', '')) !== false) {
                if (!$headerSeen) {
                    $headerSeen = true;
                    continue;
                }
                $stopIndex = $map['stop_id'] ?? null;
                $id = $stopIndex !== null ? ($row[$stopIndex] ?? '') : '';
                if ($id === '' || !isset($wanted[$id])) {
                    continue;
                }
                fputcsv($out, $row, ',', '"', '');
                $fileBytes = (int) ftell($out);
                $this->assertSubsetSize($fileBytes, $totalBytes + $fileBytes);
            }
            $fileBytes = (int) ftell($out);
            $this->assertSubsetSize($fileBytes, $totalBytes + $fileBytes);
            $totalBytes += $fileBytes;
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function copyEntry(\ZipArchive $zip, string $name, string $outputPath, int &$totalBytes): void
    {
        $entry = $zip->statName($name);
        if ($entry === false || (int) $entry['size'] > self::MAX_SMALL_FILE_BYTES) {
            return;
        }
        $in = $zip->getStream($name);
        if (!is_resource($in)) {
            return;
        }
        $out = fopen($outputPath, 'wb');
        if ($out === false) {
            fclose($in);

            throw new \RuntimeException("Could not write the GTFS subset file {$name}.");
        }
        try {
            $written = stream_copy_to_stream($in, $out);
            if ($written === false) {
                throw new \RuntimeException("Could not copy the GTFS subset file {$name}.");
            }
            $this->assertSubsetSize($written, $totalBytes + $written);
            $totalBytes += $written;
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function assertSubsetSize(int $entryBytes, int $totalBytes): void
    {
        if ($entryBytes > GtfsService::SUBSET_MAX_ENTRY_BYTES || $totalBytes > GtfsService::SUBSET_MAX_TOTAL_BYTES) {
            throw new FunctionalException(
                message: 'The selected GTFS routes produce a timetable that is too large. Select fewer routes.',
                errorKey: ErrorKey::GTFS_SUBSET_TOO_LARGE,
            );
        }
    }

    private function writeSubsetZip(string $tmpDir, string $subsetPath): void
    {
        $tmpZip = $subsetPath . '.tmp.' . getmypid();
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the GTFS subset zip.');
        }
        try {
            $files = glob($tmpDir . '/*') ?: [];
            foreach ($files as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();

            $this->gtfs->validate($tmpZip);
            if (!rename($tmpZip, $subsetPath)) {
                throw new \RuntimeException('Could not store the GTFS subset zip.');
            }
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($tmpZip);

            throw $e;
        }
    }
}
