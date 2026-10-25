<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TransitCatalogSuggestion;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Serves the Mobility Database catalog snapshot and ranks feeds around a map viewport.
 *
 * The snapshot is refreshed lazily by reads and once a day by the scheduled command.
 * A failed refresh never discards the previous file: a stale catalog still ranks.
 */
final readonly class TransitCatalogService
{
    private const int TTL_SECONDS = 86400;
    private const int MAX_BYTES = 10 * 1024 * 1024;
    private const float FETCH_TIMEOUT_SECONDS = 20.0;
    private const float FETCH_MAX_DURATION_SECONDS = 30.0;
    private const float STALE_FETCH_TIMEOUT_SECONDS = 5.0;
    private const float STALE_FETCH_MAX_DURATION_SECONDS = 5.0;
    private const float LOCAL_F1_THRESHOLD = 0.5;
    private const float REGIONAL_F1_THRESHOLD = 0.02;
    private const int RETRY_BACKOFF_SECONDS = 300;
    private const int SIZE_TTL_SECONDS = 86400;
    private const float SIZE_HEAD_TIMEOUT_SECONDS = 5.0;

    private const array EXPECTED_COLUMNS = [
        'id',
        'data_type',
        'entity_type',
        'location.country_code',
        'location.subdivision_name',
        'location.municipality',
        'provider',
        'is_official',
        'name',
        'note',
        'feed_contact_email',
        'static_reference',
        'urls.direct_download',
        'urls.authentication_type',
        'urls.authentication_info',
        'urls.api_key_parameter_name',
        'urls.latest',
        'urls.license',
        'location.bounding_box.minimum_latitude',
        'location.bounding_box.maximum_latitude',
        'location.bounding_box.minimum_longitude',
        'location.bounding_box.maximum_longitude',
        'location.bounding_box.extracted_on',
        'status',
        'features',
        'redirect.id',
        'redirect.comment',
    ];

    public function __construct(
        private OutboundHttpFetcher $fetcher,
        private LoggerInterface $logger,
        #[Autowire('%app.transit_catalog_file%')]
        private string $catalogFile,
        #[Autowire('%app.transit_catalog_url%')]
        private string $catalogUrl,
        #[Autowire('%app.transit_catalog_sizes_file%')]
        private string $sizesFile,
    ) {
    }

    /**
     * Downloads the catalog into a temporary file and atomically replaces the snapshot.
     *
     * @return int the number of data rows in the new snapshot
     */
    public function refresh(): int
    {
        return $this->refreshWithin(self::FETCH_TIMEOUT_SECONDS, self::FETCH_MAX_DURATION_SECONDS);
    }

    /**
     * @return list<TransitCatalogSuggestion>
     */
    public function search(
        float $swLat,
        float $swLng,
        float $neLat,
        float $neLng,
        string $query,
        int $limit = 15,
    ): array {
        $this->ensureSnapshot();
        $catalog = $this->readCatalog();
        if ($catalog === null) {
            return [];
        }

        $queryBox = ['minLat' => $swLat, 'maxLat' => $neLat, 'minLng' => $swLng, 'maxLng' => $neLng];
        [$strong, $national] = $this->rank($catalog, $queryBox, $query);
        $selected = $strong === []
            ? $national
            : array_merge($strong, array_filter($national, static fn (array $hit): bool => $hit['text'] > 0.0));

        $limited = array_slice($selected, 0, max(1, min(50, $limit)));

        return $this->withSizes(array_map(
            static fn (array $hit): TransitCatalogSuggestion => $hit['suggestion'],
            $limited,
        ));
    }

    /**
     * @param list<TransitCatalogSuggestion> $suggestions
     *
     * @return list<TransitCatalogSuggestion>
     */
    private function withSizes(array $suggestions): array
    {
        if ($suggestions === []) {
            return [];
        }

        $sizes = $this->readSizes();
        $now = time();
        $changed = false;
        $result = [];
        foreach ($suggestions as $suggestion) {
            $url = $suggestion->downloadUrl;
            $entry = $sizes[$url] ?? null;
            if ($this->sizeEntryIsFresh($entry, $now)) {
                $size = is_array($entry) && is_int($entry['size'] ?? null) ? $entry['size'] : null;
            } else {
                $size = null;
                try {
                    $size = $this->fetcher->headContentLength(
                        $url,
                        self::SIZE_HEAD_TIMEOUT_SECONDS,
                        self::SIZE_HEAD_TIMEOUT_SECONDS,
                    );
                } catch (\Throwable) {
                    $this->logger->warning('Transit catalog size lookup failed for {id}.', ['id' => $suggestion->id]);
                }
                $sizes[$url] = ['size' => $size, 'at' => $now];
                $changed = true;
            }
            $result[] = $suggestion->withSizeBytes($size);
        }

        if ($changed) {
            $this->writeSizes($sizes);
        }

        return $result;
    }

    private function sizeEntryIsFresh(mixed $entry, int $now): bool
    {
        if (!is_array($entry) || !array_key_exists('size', $entry)) {
            return false;
        }
        $at = $entry['at'] ?? null;
        if (!is_int($at) || $at <= $now - self::SIZE_TTL_SECONDS) {
            return false;
        }

        return $entry['size'] === null || is_int($entry['size']);
    }

    /**
     * @return array<string, array{size: ?int, at: int}>
     */
    private function readSizes(): array
    {
        $raw = @file_get_contents($this->sizesFile);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $sizes = [];
        foreach ($decoded as $url => $entry) {
            if (!is_string($url) || !is_array($entry)) {
                continue;
            }
            $size = $entry['size'] ?? null;
            $at = $entry['at'] ?? null;
            if (($size === null || is_int($size)) && is_int($at)) {
                $sizes[$url] = ['size' => $size, 'at' => $at];
            }
        }

        return $sizes;
    }

    /**
     * @param array<string, array{size: ?int, at: int}> $sizes
     */
    private function writeSizes(array $sizes): void
    {
        $directory = dirname($this->sizesFile);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            $this->logger->warning('Failed to create the transit catalog sizes directory.');

            return;
        }
        $lock = @fopen($this->sizesFile . '.lock', 'c');
        if ($lock === false) {
            return;
        }

        try {
            // ponytail: non-blocking; a concurrent search owns the write and the next one retries.
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }
            $temporary = $this->sizesFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
            try {
                $json = json_encode($sizes, JSON_THROW_ON_ERROR);
                if (file_put_contents($temporary, $json) === false || !rename($temporary, $this->sizesFile)) {
                    throw new \RuntimeException('Failed to write the transit catalog sizes cache.');
                }
            } catch (\Throwable) {
                @unlink($temporary);
                $this->logger->warning('Failed to write the transit catalog sizes cache.');
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    private function ensureSnapshot(): void
    {
        clearstatcache(true, $this->catalogFile);
        if ($this->hasRecentFailure()) {
            return;
        }
        if (!is_file($this->catalogFile)) {
            $this->refreshLazily(
                'Transit catalog download failed: {error}',
                self::FETCH_TIMEOUT_SECONDS,
                self::FETCH_MAX_DURATION_SECONDS,
            );

            return;
        }

        $modifiedAt = filemtime($this->catalogFile);
        if ($modifiedAt !== false && time() - $modifiedAt < self::TTL_SECONDS) {
            return;
        }

        $this->refreshLazily(
            'Serving the stale transit catalog: {error}',
            self::STALE_FETCH_TIMEOUT_SECONDS,
            self::STALE_FETCH_MAX_DURATION_SECONDS,
        );
    }

    private function refreshLazily(string $failureMessage, float $timeoutSeconds, float $maxDurationSeconds): void
    {
        try {
            $this->refreshWithin($timeoutSeconds, $maxDurationSeconds);
        } catch (\Throwable $e) {
            @touch($this->failureMarker());
            $this->logger->warning($failureMessage, ['error' => $e->getMessage()]);
        }
    }

    private function hasRecentFailure(): bool
    {
        $marker = $this->failureMarker();
        clearstatcache(true, $marker);
        $failedAt = is_file($marker) ? filemtime($marker) : false;

        return $failedAt !== false && time() - $failedAt < self::RETRY_BACKOFF_SECONDS;
    }

    private function failureMarker(): string
    {
        return $this->catalogFile . '.failed';
    }

    private function refreshWithin(float $timeoutSeconds, float $maxDurationSeconds): int
    {
        $this->ensureDirectory();
        $lock = fopen($this->catalogFile . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Failed to open the transit catalog lock file.');
        }

        try {
            // ponytail: non-blocking; a concurrent refresh owns the work, so contention is a failed attempt.
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('A transit catalog refresh is already running.');
            }

            try {
                return $this->downloadAndReplace($timeoutSeconds, $maxDurationSeconds);
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    private function downloadAndReplace(float $timeoutSeconds, float $maxDurationSeconds): int
    {
        $temporary = dirname($this->catalogFile) . '/feeds-' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            $this->fetcher->fetchToFile(
                $this->catalogUrl,
                $temporary,
                self::MAX_BYTES,
                $timeoutSeconds,
                $maxDurationSeconds,
                ['Accept' => 'text/csv'],
            );
            $rowCount = $this->validateAndCount($temporary);
            if (!rename($temporary, $this->catalogFile)) {
                throw new \RuntimeException('Failed to replace the transit catalog snapshot.');
            }
            @unlink($this->failureMarker());

            return $rowCount;
        } catch (\Throwable $e) {
            @touch($this->failureMarker());
            @unlink($temporary);

            throw $e;
        }
    }

    private function validateAndCount(string $path): int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open the downloaded transit catalog.');
        }

        try {
            $header = fgetcsv($handle, escape: '');
            $columns = is_array($header)
                ? array_filter($header, static fn (mixed $column): bool => is_string($column))
                : [];
            $missing = array_diff(self::EXPECTED_COLUMNS, $columns);
            if ($missing !== []) {
                throw new \RuntimeException(
                    'The transit catalog header is missing columns: ' . implode(', ', $missing) . '.',
                );
            }

            $count = 0;
            while (($fields = fgetcsv($handle, escape: '')) !== false) {
                if ($fields !== [null]) {
                    ++$count;
                }
            }

            return $count;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{
     *     static: list<array{
     *         id: string,
     *         provider: string,
     *         name: string,
     *         municipality: string,
     *         countryCode: string,
     *         licenseUrl: ?string,
     *         latest: string,
     *         direct: string,
     *         bbox: array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     *     }>,
     *     realtime: array<string, string>
     * }|null
     */
    private function readCatalog(): ?array
    {
        $handle = @fopen($this->catalogFile, 'rb');
        if ($handle === false) {
            $this->logger->warning('The transit catalog snapshot cannot be opened.');

            return null;
        }

        try {
            $header = fgetcsv($handle, escape: '');
            if (!is_array($header)) {
                return null;
            }

            $map = $this->headerMap($header);
            $static = [];
            $realtime = [];
            while (($fields = fgetcsv($handle, escape: '')) !== false) {
                $this->collectRow($map, $fields, $static, $realtime);
            }

            return ['static' => $static, 'realtime' => $realtime];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<int, string|null> $header
     *
     * @return array<string, int>
     */
    private function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $column) {
            if (is_string($column)) {
                $map[trim($column)] = $index;
            }
        }

        return $map;
    }

    /**
     * @param array<string, int> $map
     * @param array<int, string|null> $fields
     * @param list<array{
     *     id: string,
     *     provider: string,
     *     name: string,
     *     municipality: string,
     *     countryCode: string,
     *     licenseUrl: ?string,
     *     latest: string,
     *     direct: string,
     *     bbox: array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     * }> $static
     * @param array<string, string> $realtime
     */
    private function collectRow(array $map, array $fields, array &$static, array &$realtime): void
    {
        $dataType = strtolower($this->value($map, $fields, 'data_type'));
        if ($dataType === 'gtfs_rt') {
            $this->collectRealtime($map, $fields, $realtime);

            return;
        }
        if ($dataType !== 'gtfs') {
            return;
        }

        $row = $this->staticRow($map, $fields);
        if ($row !== null) {
            $static[] = $row;
        }
    }

    /**
     * @param array<string, int> $map
     * @param array<int, string|null> $fields
     * @param array<string, string> $realtime
     */
    private function collectRealtime(array $map, array $fields, array &$realtime): void
    {
        $entityType = strtolower($this->value($map, $fields, 'entity_type'));
        $authentication = $this->value($map, $fields, 'urls.authentication_type');
        $url = $this->value($map, $fields, 'urls.direct_download');
        if ($url === '') {
            $url = $this->value($map, $fields, 'urls.latest');
        }
        $url = $this->secureUrl($url);

        if (
            !str_contains($entityType, 'tu')
            || ($authentication !== '' && $authentication !== '0')
            || strtolower($this->value($map, $fields, 'status')) !== 'active'
            || $url === ''
        ) {
            return;
        }

        foreach (explode('|', $this->value($map, $fields, 'static_reference')) as $reference) {
            $staticId = trim($reference);
            if ($staticId !== '' && !isset($realtime[$staticId])) {
                $realtime[$staticId] = $url;
            }
        }
    }

    /**
     * @param array<string, int> $map
     * @param array<int, string|null> $fields
     *
     * @return array{
     *     id: string,
     *     provider: string,
     *     name: string,
     *     municipality: string,
     *     countryCode: string,
     *     licenseUrl: ?string,
     *     latest: string,
     *     direct: string,
     *     bbox: array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     * }|null
     */
    private function staticRow(array $map, array $fields): ?array
    {
        $id = $this->value($map, $fields, 'id');
        $authentication = $this->value($map, $fields, 'urls.authentication_type');
        $latest = $this->secureUrl($this->value($map, $fields, 'urls.latest'));
        $direct = $this->secureUrl($this->value($map, $fields, 'urls.direct_download'));
        $download = $latest !== '' ? $latest : $direct;
        $bbox = $this->boundingBox($map, $fields);

        if (
            $id === ''
            || $bbox === null
            || strtolower($this->value($map, $fields, 'status')) !== 'active'
            || $this->value($map, $fields, 'redirect.id') !== ''
            || strtolower($this->value($map, $fields, 'is_official')) !== 'true'
            || ($authentication !== '' && $authentication !== '0')
            || $download === ''
        ) {
            return null;
        }

        $license = $this->value($map, $fields, 'urls.license');

        return [
            'id' => $id,
            'provider' => $this->value($map, $fields, 'provider'),
            'name' => $this->value($map, $fields, 'name'),
            'municipality' => $this->value($map, $fields, 'location.municipality'),
            'countryCode' => $this->value($map, $fields, 'location.country_code'),
            'licenseUrl' => $license !== '' ? $license : null,
            'latest' => $latest,
            'direct' => $direct,
            'bbox' => $bbox,
        ];
    }

    /**
     * @param array<string, int> $map
     * @param array<int, string|null> $fields
     *
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}|null
     */
    private function boundingBox(array $map, array $fields): ?array
    {
        $minLat = $this->coordinate($map, $fields, 'location.bounding_box.minimum_latitude');
        $maxLat = $this->coordinate($map, $fields, 'location.bounding_box.maximum_latitude');
        $minLng = $this->coordinate($map, $fields, 'location.bounding_box.minimum_longitude');
        $maxLng = $this->coordinate($map, $fields, 'location.bounding_box.maximum_longitude');

        if (
            $minLat === null
            || $maxLat === null
            || $minLng === null
            || $maxLng === null
            || $minLat < -90.0
            || $maxLat > 90.0
            || $minLng < -180.0
            || $maxLng > 180.0
            || $minLat > $maxLat
            || $minLng > $maxLng
        ) {
            return null;
        }

        return ['minLat' => $minLat, 'maxLat' => $maxLat, 'minLng' => $minLng, 'maxLng' => $maxLng];
    }

    /**
     * @param array<string, int> $map
     * @param array<int, string|null> $fields
     */
    private function coordinate(array $map, array $fields, string $column): ?float
    {
        $raw = $this->value($map, $fields, $column);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        return is_finite($value) ? $value : null;
    }

    /**
     * @param array<string, int> $map
     * @param array<int, string|null> $fields
     */
    private function value(array $map, array $fields, string $column): string
    {
        $index = $map[$column] ?? null;
        if ($index === null) {
            return '';
        }

        return trim($fields[$index] ?? '');
    }

    private function secureUrl(string $url): string
    {
        return str_starts_with(strtolower($url), 'https://') ? $url : '';
    }

    /**
     * @param array{
     *     static: list<array{
     *         id: string,
     *         provider: string,
     *         name: string,
     *         municipality: string,
     *         countryCode: string,
     *         licenseUrl: ?string,
     *         latest: string,
     *         direct: string,
     *         bbox: array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     *     }>,
     *     realtime: array<string, string>
     * } $catalog
     * @param array{minLat: float, maxLat: float, minLng: float, maxLng: float} $queryBox
     *
     * @return array{
     *     list<array{score: float, text: float, suggestion: TransitCatalogSuggestion}>,
     *     list<array{score: float, text: float, suggestion: TransitCatalogSuggestion}>
     * }
     */
    private function rank(array $catalog, array $queryBox, string $query): array
    {
        $strong = [];
        $national = [];
        foreach ($catalog['static'] as $row) {
            $spatial = $this->spatialScore($row['bbox'], $queryBox);
            $text = $this->textScore($query, [$row['provider'], $row['name'], $row['municipality']]);
            $realtimeUrl = $catalog['realtime'][$row['id']] ?? null;
            $score = 0.7 * $spatial + 0.3 * $text;
            if ($score <= 0.0) {
                continue;
            }
            $latest = $row['latest'];
            $direct = $row['direct'];
            $suggestion = new TransitCatalogSuggestion(
                id: $row['id'],
                provider: $row['provider'],
                name: $row['name'],
                municipality: $row['municipality'],
                countryCode: $row['countryCode'],
                licenseUrl: $row['licenseUrl'],
                downloadUrl: $latest !== '' ? $latest : $direct,
                fallbackUrl: $latest !== '' && $direct !== '' && $latest !== $direct ? $direct : null,
                realtimeUrl: $realtimeUrl,
                live: $realtimeUrl !== null,
                scope: $this->scope($row['bbox'], $queryBox, $spatial),
            );
            $hit = ['score' => $score, 'text' => $text, 'suggestion' => $suggestion];
            if ($suggestion->scope === 'national') {
                $national[] = $hit;
            } else {
                $strong[] = $hit;
            }
        }

        usort($strong, self::compareHits(...));
        usort($national, self::compareHits(...));

        return [$strong, $national];
    }

    /**
     * @param array{score: float, text: float, suggestion: TransitCatalogSuggestion} $a
     * @param array{score: float, text: float, suggestion: TransitCatalogSuggestion} $b
     */
    private static function compareHits(array $a, array $b): int
    {
        return $b['score'] <=> $a['score'] ?: strcmp($a['suggestion']->id, $b['suggestion']->id);
    }

    /**
     * @param array{minLat: float, maxLat: float, minLng: float, maxLng: float} $feed
     * @param array{minLat: float, maxLat: float, minLng: float, maxLng: float} $query
     */
    private function spatialScore(array $feed, array $query): float
    {
        $overlapLat = min($feed['maxLat'], $query['maxLat']) - max($feed['minLat'], $query['minLat']);
        $overlapLng = min($feed['maxLng'], $query['maxLng']) - max($feed['minLng'], $query['minLng']);
        $feedArea = ($feed['maxLat'] - $feed['minLat']) * ($feed['maxLng'] - $feed['minLng']);
        $queryArea = ($query['maxLat'] - $query['minLat']) * ($query['maxLng'] - $query['minLng']);
        if ($overlapLat <= 0.0 || $overlapLng <= 0.0 || $feedArea <= 0.0 || $queryArea <= 0.0) {
            return 0.0;
        }

        $intersection = $overlapLat * $overlapLng;
        $precision = $intersection / $feedArea;
        $recall = $intersection / $queryArea;

        return 2.0 * $precision * $recall / ($precision + $recall);
    }

    /**
     * @param array{minLat: float, maxLat: float, minLng: float, maxLng: float} $feed
     * @param array{minLat: float, maxLat: float, minLng: float, maxLng: float} $query
     */
    private function scope(array $feed, array $query, float $f1): string
    {
        $contained = $feed['minLat'] >= $query['minLat']
            && $feed['maxLat'] <= $query['maxLat']
            && $feed['minLng'] >= $query['minLng']
            && $feed['maxLng'] <= $query['maxLng'];

        if ($contained || $f1 >= self::LOCAL_F1_THRESHOLD) {
            return 'local';
        }

        return $f1 >= self::REGIONAL_F1_THRESHOLD ? 'regional' : 'national';
    }

    /**
     * @param list<string> $haystacks
     */
    private function textScore(string $query, array $haystacks): float
    {
        $queryTokens = $this->tokens($query);
        if ($queryTokens === []) {
            return 0.0;
        }

        $catalogTokens = [];
        foreach ($haystacks as $haystack) {
            foreach ($this->tokens($haystack) as $token) {
                $catalogTokens[$token] = true;
            }
        }

        return count(array_intersect($queryTokens, array_keys($catalogTokens))) / count($queryTokens);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $normalized = strtolower($ascii === false ? $value : $ascii);
        $parts = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(is_array($parts) ? $parts : []));
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->catalogFile);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to create the transit catalog directory.');
        }
    }
}
