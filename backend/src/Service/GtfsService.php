<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GtfsSource;
use App\Exception\FunctionalException;
use App\Repository\GtfsSourceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    private const int MAX_DOWNLOAD_BYTES = 200_000_000;
    private const int MAX_ZIP_ENTRIES = 10_000;
    private const int MAX_ENTRY_UNCOMPRESSED_BYTES = 256 * 1024 * 1024;
    private const int MAX_TOTAL_UNCOMPRESSED_BYTES = 1024 * 1024 * 1024;
    private const int MAX_COMPRESSION_RATIO = 100;

    public function __construct(
        private GtfsSourceRepository $sources,
        #[Autowire('%app.gtfs_storage_dir%')]
        private string $gtfsStorageDir,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private OutboundUrlGuard $urlGuard,
    ) {
    }

    public function createFromUpload(UploadedFile $file, string $name, ?string $realtimeUrl = null): GtfsSource
    {
        $uuid = Uuid::v4()->toRfc4122();
        $destPath = $this->ensureStorageDir() . '/' . $uuid . '.zip';

        $file->move(dirname($destPath), basename($destPath));

        try {
            $this->validate($destPath);
            $routeCount = $this->countRoutes($destPath);
        } catch (\Throwable $e) {
            @unlink($destPath);
            throw $e;
        }

        $source = new GtfsSource($name, $destPath, $uuid);
        $source->setRouteCount($routeCount);
        $this->applyRealtimeUrl($source, $realtimeUrl);
        $this->sources->save($source);

        return $source;
    }

    public function createFromUrl(string $url, string $name, ?string $realtimeUrl = null): GtfsSource
    {
        $pinned = $this->urlGuard->resolve($url);

        $uuid = Uuid::v4()->toRfc4122();
        $destPath = $this->ensureStorageDir() . '/' . $uuid . '.zip';

        $handle = fopen($destPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open destination file: {$destPath}");
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 120,
                'max_duration' => 180,
                'max_redirects' => 0,
                'resolve' => [$pinned['host'] => $pinned['ip']],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new FunctionalException(message: "GTFS download failed: server returned HTTP {$statusCode}.", errorKey: 'gtfs.download_failed');
            }

            $bytesWritten = 0;
            foreach ($this->httpClient->stream($response) as $chunk) {
                $chunkContent = $chunk->getContent();
                $chunkLen = strlen($chunkContent);
                $bytesWritten += $chunkLen;
                if ($bytesWritten > self::MAX_DOWNLOAD_BYTES) {
                    throw new FunctionalException(
                        message: 'GTFS download exceeds maximum size of ' . (self::MAX_DOWNLOAD_BYTES / 1_000_000) . ' MB.',
                        errorKey: 'gtfs.download_too_large',
                    );
                }
                $written = fwrite($handle, $chunkContent);
                if ($written !== $chunkLen) {
                    throw new \RuntimeException('Failed to write GTFS download chunk to disk.');
                }
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($destPath);
            if ($e instanceof FunctionalException) {
                throw $e;
            }
            // Transport exceptions carry host/IP/DNS details that must not reach the client.
            $this->logger->error('GTFS download failed: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            throw new FunctionalException(message: 'Failed to download GTFS feed.', errorKey: 'gtfs.download_failed', previous: $e);
        }

        fclose($handle);

        try {
            $this->validate($destPath);
            $routeCount = $this->countRoutes($destPath);
        } catch (\Throwable $e) {
            @unlink($destPath);
            throw $e;
        }

        $source = new GtfsSource($name, $destPath, $uuid);
        $source->setSourceUrl($url);
        $source->setRouteCount($routeCount);
        $this->applyRealtimeUrl($source, $realtimeUrl);
        $this->sources->save($source);

        return $source;
    }

    private function applyRealtimeUrl(GtfsSource $source, ?string $realtimeUrl): void
    {
        $trimmed = $realtimeUrl !== null ? trim($realtimeUrl) : '';
        if ($trimmed === '') {
            return;
        }
        $this->urlGuard->assertShape($trimmed);
        $source->setRealtimeUrl($trimmed);
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
                if ($size > self::MAX_ENTRY_UNCOMPRESSED_BYTES) {
                    $issues[] = "ZIP entry exceeds the maximum uncompressed size: {$name}";
                }
                if ($size > 0 && ($compSize < 1 || $size / $compSize > self::MAX_COMPRESSION_RATIO)) {
                    $issues[] = "ZIP entry is compressed beyond the accepted ratio: {$name}";
                }
            }
        }
        if ($totalUncompressed > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
            $issues[] = 'ZIP archive uncompressed size exceeds the maximum of ' . (self::MAX_TOTAL_UNCOMPRESSED_BYTES / (1024 * 1024)) . ' MB.';
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

        if ($csv === false) {
            return 0;
        }

        $lines = explode("\n", trim($csv));
        if (count($lines) <= 1) {
            return 0;
        }

        $count = 0;
        for ($i = 1; $i < count($lines); ++$i) {
            if (trim($lines[$i]) !== '') {
                ++$count;
            }
        }

        return $count;
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
            'stops.txt' => ['stop_id', 'stop_name'],
            'stop_times.txt' => ['trip_id', 'stop_id', 'stop_sequence'],
        ];

        foreach ($expectedHeaders as $file => $requiredColumns) {
            if (!in_array($file, $presentFiles, true)) {
                continue;
            }
            $content = $zip->getFromName($file);
            if ($content === false) {
                continue;
            }
            $lines = explode("\n", trim($content));
            if ($lines[0] === '') {
                $issues[] = "Empty file: {$file}";
                continue;
            }
            $headers = str_getcsv($lines[0], escape: '\\');
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
        for ($i = 1; $i < count($lines); ++$i) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            $fields = str_getcsv($line, escape: '\\');
            $routeId = isset($headerMap['route_id']) ? ($fields[$headerMap['route_id']] ?? '') : '';
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
    }
}
