<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private OutboundUrlGuard $urlGuard,
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

    private function fetch(string $url): string
    {
        $pinned = $this->urlGuard->resolve($url);
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 5,
            'max_duration' => 5,
            'max_redirects' => 0,
            'resolve' => [$pinned['host'] => $pinned['ip']],
            'headers' => ['Accept' => 'application/x-protobuf, application/octet-stream'],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('GTFS-RT feed returned HTTP ' . $status . '.');
        }

        $content = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $content .= $chunk->getContent();
            if (strlen($content) > self::MAX_BYTES) {
                throw new \RuntimeException('GTFS-RT feed exceeds the maximum size.');
            }
        }

        return $content;
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
