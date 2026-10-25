<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GtfsSource;
use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Repository\GtfsSourceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final readonly class GtfsService
{
    private const array REQUIRED_FILES = [
        'agency.txt',
        'routes.txt',
        'trips.txt',
        'shapes.txt',
        'stops.txt',
        'stop_times.txt',
    ];

    public const int MAX_SOURCE_DOWNLOAD_BYTES = 512 * 1024 * 1024;
    public const int SUBSET_MAX_ENTRY_BYTES = 256 * 1024 * 1024;
    public const int SUBSET_MAX_TOTAL_BYTES = 1024 * 1024 * 1024;
    public const int MAX_SELECTED_ROUTES = 100;
    private const int MAX_SOURCE_ENTRY_BYTES = 2 * 1024 * 1024 * 1024;
    private const int MAX_SOURCE_TOTAL_BYTES = 4 * 1024 * 1024 * 1024;
    private const int MAX_ZIP_ENTRIES = 10_000;
    private const int MAX_COMPRESSION_RATIO = 100;

    public function __construct(
        private GtfsSourceRepository $sources,
        #[Autowire('%app.gtfs_storage_dir%')]
        private string $gtfsStorageDir,
        private OutboundHttpFetcher $fetcher,
        private LoggerInterface $logger,
        private OutboundUrlGuard $urlGuard,
        private Filesystem $filesystem,
        private GtfsShapeCompletionService $shapeCompletion,
    ) {
    }

    public function createFromUpload(UploadedFile $file, string $name, ?string $realtimeUrl = null): GtfsSource
    {
        $realtime = $this->validRealtimeUrl($realtimeUrl);
        $uuid = Uuid::v4()->toRfc4122();
        $destPath = $this->ensureStorageDir() . '/' . $uuid . '.zip';

        $file->move(dirname($destPath), basename($destPath));

        try {
            $this->validateSource($destPath, requireShapes: false);
            $this->completeShapes($destPath);
            $this->validateSource($destPath);
            $routeCount = $this->countRoutes($destPath);
        } catch (\Throwable $e) {
            @unlink($destPath);
            throw $e;
        }

        $source = new GtfsSource($name, $destPath, $uuid);
        $source->setRouteCount($routeCount);
        if ($realtime !== null) {
            $source->setRealtimeUrl($realtime);
        }
        $this->sources->save($source);

        return $source;
    }

    public function createFromUrl(
        string $url,
        string $name,
        ?string $realtimeUrl = null,
        ?string $fallbackUrl = null,
    ): GtfsSource {
        $realtime = $this->validRealtimeUrl($realtimeUrl);
        $uuid = Uuid::v4()->toRfc4122();
        $destPath = $this->ensureStorageDir() . '/' . $uuid . '.zip';

        $candidates = [$url];
        $fallback = $this->validFallbackUrl($fallbackUrl, $url);
        if ($fallback !== null) {
            $candidates[] = $fallback;
        }

        $routeCount = null;
        $usedUrl = null;
        $lastFailure = null;
        foreach ($candidates as $candidate) {
            try {
                $routeCount = $this->downloadAndValidate($candidate, $destPath);
                $usedUrl = $candidate;
                break;
            } catch (FunctionalException $e) {
                @unlink($destPath);
                $lastFailure = $e;
                $this->logger->warning('GTFS import failed for {host} ({errorKey}).', [
                    'host' => $this->hostOf($candidate),
                    'errorKey' => $e->getErrorKey(),
                ]);
            }
        }

        if ($usedUrl === null || $routeCount === null) {
            @unlink($destPath);

            throw $lastFailure ?? new FunctionalException(
                message: 'Failed to download GTFS feed.',
                errorKey: 'gtfs.download_failed',
            );
        }

        $source = new GtfsSource($name, $destPath, $uuid);
        $source->setSourceUrl($usedUrl);
        $source->setRouteCount($routeCount);
        if ($realtime !== null) {
            $source->setRealtimeUrl($realtime);
        }
        $this->sources->save($source);

        return $source;
    }

    private function downloadAndValidate(string $url, string $destination): int
    {
        try {
            $this->fetcher->fetchToFile(
                $url,
                $destination,
                self::MAX_SOURCE_DOWNLOAD_BYTES,
                300.0,
                600.0,
            );
        } catch (FunctionalException $e) {
            throw $e;
        } catch (\LengthException $e) {
            throw new FunctionalException(
                message: 'The GTFS source exceeds the maximum size of ' . (self::MAX_SOURCE_DOWNLOAD_BYTES / (1024 * 1024)) . ' MB.',
                errorKey: ErrorKey::GTFS_SOURCE_TOO_LARGE,
                previous: $e,
            );
        } catch (\Throwable $e) {
            throw new FunctionalException(
                message: 'Failed to download GTFS feed.',
                errorKey: 'gtfs.download_failed',
                previous: $e,
            );
        }

        $this->validateSource($destination, requireShapes: false);
        $this->completeShapes($destination);
        $this->validateSource($destination);

        return $this->countRoutes($destination);
    }

    private function completeShapes(string $zipPath): void
    {
        $result = $this->shapeCompletion->completeIfNeeded($zipPath);
        if (!$result['completed']) {
            throw new FunctionalException(
                message: 'This GTFS feed has no route shapes, which the map needs.',
                errorKey: ErrorKey::GTFS_SHAPES_MISSING,
            );
        }
    }

    private function validFallbackUrl(?string $fallbackUrl, string $primaryUrl): ?string
    {
        $trimmed = $fallbackUrl !== null ? trim($fallbackUrl) : '';
        if ($trimmed === '' || $trimmed === $primaryUrl || mb_strlen($trimmed) > 1000) {
            if ($trimmed !== '' && $trimmed !== $primaryUrl) {
                $this->logger->warning('Ignoring a fallback GTFS URL longer than 1000 characters.');
            }

            return null;
        }

        try {
            $this->urlGuard->assertShape($trimmed);
        } catch (FunctionalException $e) {
            $this->logger->warning(
                'Ignoring an invalid fallback GTFS URL ({errorKey}).',
                ['errorKey' => $e->getErrorKey()],
            );

            return null;
        }

        return $trimmed;
    }

    private function validRealtimeUrl(?string $realtimeUrl): ?string
    {
        $trimmed = $realtimeUrl !== null ? trim($realtimeUrl) : '';
        if ($trimmed === '') {
            return null;
        }
        $this->urlGuard->assertShape($trimmed);

        return $trimmed;
    }

    private function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : 'unknown';
    }

    /** @return list<array{routeId: string, shortName: string, longName: string, routeType: int, color: string, textColor: string}> */
    public function extractRoutes(GtfsSource $source): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($source->getFilePath()) !== true) {
            throw new FunctionalException(message: 'Failed to open GTFS archive for route extraction.', errorKey: 'gtfs.archive_open_failed');
        }

        $csv = $zip->getFromName('routes.txt');
        if ($csv === false) {
            $zip->close();

            throw new FunctionalException(message: 'routes.txt not found in GTFS archive.', errorKey: 'gtfs.routes_txt_missing');
        }

        $zip->close();

        return $this->parseRoutesCsv($csv);
    }

    /**
     * Validates a downloaded source, not the generated subset.
     *
     * Large national feeds are legitimate sources: their uncompressed size is only
     * checked against the source caps here, while the subset caps apply to the
     * route-filtered archive that is actually processed.
     *
     * @return list<string>
     */
    public function validateSource(string $zipPath, bool $requireShapes = true): array
    {
        $fileSize = is_file($zipPath) ? (int) filesize($zipPath) : 0;
        if ($fileSize > self::MAX_SOURCE_DOWNLOAD_BYTES) {
            throw new FunctionalException(
                message: 'The GTFS source exceeds the maximum size of ' . (self::MAX_SOURCE_DOWNLOAD_BYTES / (1024 * 1024)) . ' MB.',
                errorKey: ErrorKey::GTFS_SOURCE_TOO_LARGE,
            );
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new FunctionalException(
                message: 'Invalid GTFS archive: failed to open ZIP file.',
                errorKey: 'gtfs.invalid_archive',
            );
        }

        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();

            throw new FunctionalException(
                message: 'Invalid GTFS archive: ZIP archive contains too many entries (' . $zip->numFiles . '). Maximum is ' . self::MAX_ZIP_ENTRIES . '.',
                errorKey: 'gtfs.invalid_archive',
            );
        }

        $issues = [];
        $presentFiles = [];
        $totalUncompressed = 0;
        $maxEntry = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = $zip->statIndex($i);
            if ($entry === false) {
                continue;
            }
            $name = $entry['name'];
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $issues[] = "Rejected suspicious ZIP entry: {$name}";
                continue;
            }
            $presentFiles[] = $name;

            $entrySize = (int) $entry['size'];
            $compSize = (int) $entry['comp_size'];
            $totalUncompressed += $entrySize;
            $maxEntry = max($maxEntry, $entrySize);
            if ($entrySize > 0 && ($compSize < 1 || $entrySize / $compSize > self::MAX_COMPRESSION_RATIO)) {
                $issues[] = "ZIP entry is compressed beyond the accepted ratio: {$name}";
            }
        }

        if ($maxEntry > self::MAX_SOURCE_ENTRY_BYTES || $totalUncompressed > self::MAX_SOURCE_TOTAL_BYTES) {
            $zip->close();

            throw new FunctionalException(
                message: 'The GTFS source expands beyond the supported size.',
                errorKey: ErrorKey::GTFS_SOURCE_UNCOMPRESSED_TOO_LARGE,
            );
        }

        if ($issues === []) {
            foreach (self::REQUIRED_FILES as $required) {
                if (($required !== 'shapes.txt' || $requireShapes) && !in_array($required, $presentFiles, true)) {
                    $issues[] = "Missing required file: {$required}";
                }
            }
            $issues = array_merge($issues, $this->validateHeaders($zip, $presentFiles));
        }

        $zip->close();

        if ($requireShapes && in_array('Missing required file: shapes.txt', $issues, true)) {
            throw new FunctionalException(
                message: 'This GTFS feed has no route shapes, which the map needs.',
                errorKey: ErrorKey::GTFS_SHAPES_MISSING,
            );
        }

        if ($issues !== []) {
            throw new FunctionalException(message: 'Invalid GTFS archive: ' . implode('; ', $issues), errorKey: 'gtfs.invalid_archive');
        }

        return [];
    }

    /**
     * @return array{sizeBytes: int, uncompressedBytes: ?int, requiresSubset: bool}
     */
    public function sourceMetadata(GtfsSource $source): array
    {
        $path = $source->getFilePath();
        $sizeBytes = is_file($path) ? (int) filesize($path) : 0;

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return ['sizeBytes' => $sizeBytes, 'uncompressedBytes' => null, 'requiresSubset' => false];
        }

        $total = 0;
        $maxEntry = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = $zip->statIndex($i);
            if ($entry === false) {
                continue;
            }
            $size = (int) $entry['size'];
            $total += $size;
            $maxEntry = max($maxEntry, $size);
        }
        $zip->close();

        return [
            'sizeBytes' => $sizeBytes,
            'uncompressedBytes' => $total,
            'requiresSubset' => $maxEntry > self::SUBSET_MAX_ENTRY_BYTES || $total > self::SUBSET_MAX_TOTAL_BYTES,
        ];
    }

    /** @return list<string> */
    public function validate(string $zipPath): array
    {
        $issues = [];

        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            return ['Failed to open ZIP file (error code: ' . $openResult . ').'];
        }

        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();

            throw new FunctionalException(
                message: 'Invalid GTFS archive: ZIP archive contains too many entries (' . $zip->numFiles . '). Maximum is ' . self::MAX_ZIP_ENTRIES . '.',
                errorKey: 'gtfs.invalid_archive',
            );
        }

        $presentFiles = [];
        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = $zip->statIndex($i);
            if ($entry !== false) {
                $name = $entry['name'];
                if (str_contains($name, '..') || str_starts_with($name, '/')) {
                    $issues[] = "Rejected suspicious ZIP entry: {$name}";
                    continue;
                }
                $presentFiles[] = $name;

                $size = (int) $entry['size'];
                $compSize = (int) $entry['comp_size'];
                $totalUncompressed += $size;
                if ($size > self::SUBSET_MAX_ENTRY_BYTES) {
                    $issues[] = "ZIP entry exceeds the maximum uncompressed size: {$name}";
                }
                if ($size > 0 && ($compSize < 1 || $size / $compSize > self::MAX_COMPRESSION_RATIO)) {
                    $issues[] = "ZIP entry is compressed beyond the accepted ratio: {$name}";
                }
            }
        }
        if ($totalUncompressed > self::SUBSET_MAX_TOTAL_BYTES) {
            $issues[] = 'ZIP archive uncompressed size exceeds the maximum of ' . (self::SUBSET_MAX_TOTAL_BYTES / (1024 * 1024)) . ' MB.';
        }

        // Size/ratio issues mean an entry could OOM validateHeaders' getFromName(); reject first.
        if ($issues === []) {
            foreach (self::REQUIRED_FILES as $required) {
                if (!in_array($required, $presentFiles, true)) {
                    $issues[] = "Missing required file: {$required}";
                }
            }
            $issues = array_merge($issues, $this->validateHeaders($zip, $presentFiles));
        }

        $zip->close();

        if (in_array('Missing required file: shapes.txt', $issues, true)) {
            throw new FunctionalException(
                message: 'This GTFS feed has no route shapes, which the map needs.',
                errorKey: ErrorKey::GTFS_SHAPES_MISSING,
            );
        }

        if ($issues !== []) {
            throw new FunctionalException(message: 'Invalid GTFS archive: ' . implode('; ', $issues), errorKey: 'gtfs.invalid_archive');
        }

        return $issues;
    }

    private function ensureStorageDir(): string
    {
        if (!is_dir($this->gtfsStorageDir)) {
            if (!mkdir($this->gtfsStorageDir, 0o755, true) && !is_dir($this->gtfsStorageDir)) {
                throw new \RuntimeException("Failed to create GTFS storage directory: {$this->gtfsStorageDir}");
            }
        }

        return $this->gtfsStorageDir;
    }

    private function countRoutes(string $zipPath): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return 0;
        }

        $csv = $zip->getFromName('routes.txt');
        $zip->close();

        return $csv === false ? 0 : count($this->parseRoutesCsv($csv));
    }

    /**
     * @param list<string> $presentFiles
     * @return list<string>
     */
    private function validateHeaders(\ZipArchive $zip, array $presentFiles): array
    {
        $issues = [];
        $expectedHeaders = [
            'routes.txt' => ['route_id', 'route_short_name', 'route_long_name', 'route_type'],
            'trips.txt' => ['route_id', 'service_id', 'trip_id'],
            'shapes.txt' => ['shape_id', 'shape_pt_lat', 'shape_pt_lon', 'shape_pt_sequence'],
            'stops.txt' => ['stop_id', 'stop_name'],
            'stop_times.txt' => ['trip_id', 'stop_id', 'stop_sequence'],
        ];

        foreach ($expectedHeaders as $file => $requiredColumns) {
            if (!in_array($file, $presentFiles, true)) {
                continue;
            }
            $stream = $zip->getStream($file);
            if (!is_resource($stream)) {
                continue;
            }
            $line = fgets($stream, 65536);
            fclose($stream);
            if ($line === false || trim($line) === '') {
                $issues[] = "Empty file: {$file}";
                continue;
            }
            $headers = str_getcsv(trim($line), escape: '\\');
            foreach ($requiredColumns as $col) {
                if (!in_array($col, $headers, true)) {
                    $issues[] = "Missing required column '{$col}' in {$file}";
                }
            }
        }

        return $issues;
    }

    /** @return list<array{routeId: string, shortName: string, longName: string, routeType: int, color: string, textColor: string}> */
    private function parseRoutesCsv(string $csv): array
    {
        $lines = explode("\n", trim($csv));
        if (count($lines) <= 1) {
            return [];
        }

        $headers = str_getcsv($lines[0], escape: '\\');
        /** @var array<int|string, int> $headerMap */
        $headerMap = array_flip(array_filter($headers, static fn (mixed $h): bool => $h !== null));

        $routes = [];
        $seenRouteIds = [];
        for ($i = 1; $i < count($lines); ++$i) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            $fields = str_getcsv($line, escape: '\\');
            $routeId = isset($headerMap['route_id']) ? ($fields[$headerMap['route_id']] ?? '') : '';
            if ($routeId !== '' && isset($seenRouteIds[$routeId])) {
                continue;
            }
            $seenRouteIds[$routeId] = true;
            $shortName = isset($headerMap['route_short_name']) ? ($fields[$headerMap['route_short_name']] ?? '') : '';
            $longName = isset($headerMap['route_long_name']) ? ($fields[$headerMap['route_long_name']] ?? '') : '';
            $rawType = isset($headerMap['route_type']) ? ($fields[$headerMap['route_type']] ?? '0') : '0';
            $routeType = is_numeric($rawType) ? (int) $rawType : 0;
            $color = isset($headerMap['route_color']) ? ($fields[$headerMap['route_color']] ?? '') : '';
            $textColor = isset($headerMap['route_text_color']) ? ($fields[$headerMap['route_text_color']] ?? '') : '';

            $routes[] = [
                'routeId' => $routeId,
                'shortName' => $shortName,
                'longName' => $longName,
                'routeType' => $routeType,
                'color' => $color,
                'textColor' => $textColor,
            ];
        }

        return $routes;
    }

    public function deleteFile(GtfsSource $source): void
    {
        $path = $source->getFilePath();
        if ($path !== '' && file_exists($path)) {
            @unlink($path);
        }
        if ($path !== '') {
            $subsets = dirname($path) . '/subsets/' . $source->getUuid();
            if (is_dir($subsets)) {
                $this->filesystem->remove($subsets);
            }
        }
    }
}
