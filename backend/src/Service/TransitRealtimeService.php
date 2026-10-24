<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\FunctionalException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Fetches and decodes a GTFS-RT trip-update feed.
 *
 * The result is cached for a few seconds so a station board refresh does not hit
 * the transport API for every open sheet. Every failure resolves to an empty map,
 * which makes the departures service fall back to the static schedule.
 */
final readonly class TransitRealtimeService
{
    private const int CACHE_TTL_SECONDS = 25;
    private const int MAX_FEED_AGE_SECONDS = 300;
    private const int MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @param list<string> $decoderCommand
     */
    public function __construct(
        private OutboundHttpFetcher $fetcher,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private array $decoderCommand = ['/opt/osm2gtfs/bin/python', '/app/bin/gtfs_rt_decode.py'],
    ) {
    }

    /**
     * @return array<string, array{
     *     startDate: ?string,
     *     delay: ?int,
     *     stops: list<array{stopId: string, seq: ?int, time: ?int, delay: ?int}>
     * }>
     */
    public function tripUpdates(string $sourceUuid, string $url): array
    {
        $key = 'gtfs_rt_' . $sourceUuid . '_' . md5($url);

        return $this->cache->get($key, function (ItemInterface $item) use ($sourceUuid, $url): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            try {
                $decoded = $this->decode($this->fetch($url));
                if (($decoded['type'] ?? '') !== 'trip_update') {
                    return [];
                }
                $header = $decoded['headerTimestamp'] ?? null;
                if (is_int($header) && $header > 0 && time() - $header > self::MAX_FEED_AGE_SECONDS) {
                    $this->logger->warning('GTFS-RT feed is stale for source {source}.', ['source' => $sourceUuid]);

                    return [];
                }
                $trips = $decoded['trips'] ?? [];

                return is_array($trips) ? $this->normalizeTrips($trips) : [];
            } catch (\Throwable $e) {
                $this->logger->warning('GTFS-RT fetch failed for source {source}: {error}', [
                    'source' => $sourceUuid,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * One-shot validation of a host-supplied GTFS-RT URL. Bypasses the cache and never throws:
     * the app shows the result beside the field before the source is imported.
     *
     * @return array{status: string, feedType: ?string, headerAgeSeconds: ?int, tripCount: int}
     */
    public function probe(string $url): array
    {
        try {
            $bytes = $this->fetch($url);
        } catch (FunctionalException) {
            return $this->probeResult('invalid_url');
        } catch (\Throwable) {
            return $this->probeResult('unreachable');
        }

        try {
            $decoded = $this->decode($bytes);
        } catch (\Throwable) {
            return $this->probeResult('invalid');
        }

        $type = is_string($decoded['type'] ?? null) ? $decoded['type'] : 'none';
        $header = $decoded['headerTimestamp'] ?? null;
        $age = is_int($header) && $header > 0 ? max(0, time() - $header) : null;

        if ($type === 'trip_update') {
            $trips = is_array($decoded['trips'] ?? null) ? $decoded['trips'] : [];

            return [
                'status' => $age !== null && $age > self::MAX_FEED_AGE_SECONDS ? 'stale' : 'ok',
                'feedType' => 'trip_update',
                'headerAgeSeconds' => $age,
                'tripCount' => \count($trips),
            ];
        }

        if ($type === 'vehicle') {
            return [
                'status' => 'wrong_type',
                'feedType' => 'vehicle',
                'headerAgeSeconds' => $age,
                'tripCount' => 0,
            ];
        }

        return $this->probeResult('invalid');
    }

    /**
     * @return array{status: string, feedType: ?string, headerAgeSeconds: ?int, tripCount: int}
     */
    private function probeResult(string $status): array
    {
        return [
            'status' => $status,
            'feedType' => null,
            'headerAgeSeconds' => null,
            'tripCount' => 0,
        ];
    }

    private function fetch(string $url): string
    {
        return $this->fetcher->fetchToString(
            $url,
            self::MAX_BYTES,
            5.0,
            5.0,
            ['Accept' => 'application/x-protobuf, application/octet-stream'],
        );
    }

    /** @return array<mixed> */
    private function decode(string $bytes): array
    {
        $process = new Process($this->decoderCommand, null, null, $bytes, 5);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('GTFS-RT decode failed: ' . trim($process->getErrorOutput()));
        }

        $decoded = json_decode($process->getOutput(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $trips
     * @return array<string, array{
     *     startDate: ?string,
     *     delay: ?int,
     *     stops: list<array{stopId: string, seq: ?int, time: ?int, delay: ?int}>
     * }>
     */
    private function normalizeTrips(array $trips): array
    {
        $normalized = [];
        foreach ($trips as $tripId => $trip) {
            if (!is_string($tripId) || $tripId === '' || !is_array($trip)) {
                continue;
            }
            $startDate = $trip['startDate'] ?? null;
            $delay = $trip['delay'] ?? null;
            $stops = [];
            foreach (is_array($trip['stops'] ?? null) ? $trip['stops'] : [] as $stop) {
                if (!is_array($stop) || !is_string($stop['stopId'] ?? null) || $stop['stopId'] === '') {
                    continue;
                }
                $stops[] = [
                    'stopId' => $stop['stopId'],
                    'seq' => is_int($stop['seq'] ?? null) ? $stop['seq'] : null,
                    'time' => is_int($stop['time'] ?? null) ? $stop['time'] : null,
                    'delay' => is_int($stop['delay'] ?? null) ? $stop['delay'] : null,
                ];
            }
            $normalized[$tripId] = [
                'startDate' => is_string($startDate) ? $startDate : null,
                'delay' => is_int($delay) ? $delay : null,
                'stops' => $stops,
            ];
        }

        return $normalized;
    }
}
