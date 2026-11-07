<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TrafficAlert;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Fetches a SIRI Lite general-message (alerts) endpoint for a profile that carries one.
 *
 * Responses are cached briefly so a polling map does not hammer the operator API. Every failure
 * resolves to an empty list; logs never carry tokens or response bodies.
 */
final readonly class SiriAlertsClient
{
    private const int ALERTS_TTL_SECONDS = 45;
    private const int FAILURE_TTL_SECONDS = 45;
    private const int MAX_BYTES = 8 * 1024 * 1024;
    private const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private OutboundHttpFetcher $fetcher,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<TrafficAlert>
     */
    public function alerts(SiriProfile $profile): array
    {
        $endpoint = $profile->alertsEndpoint;
        if ($endpoint === null || !$profile->available) {
            return [];
        }

        $source = 'siri_lite:' . $profile->key;

        return $this->cache->get(
            'siri_alerts_' . $profile->key . '_' . md5($endpoint),
            function (ItemInterface $item) use ($profile, $endpoint, $source): array {
                $item->expiresAfter(self::ALERTS_TTL_SECONDS);

                try {
                    $body = $this->fetcher->fetchToString(
                        $endpoint,
                        self::MAX_BYTES,
                        self::TIMEOUT_SECONDS,
                        self::TIMEOUT_SECONDS,
                        ['Accept' => 'application/json'] + $profile->authHeaders,
                    );
                    $alerts = SiriAlertsParser::parse($body, $source);
                    $this->logger->debug('SIRI alerts for profile {profile} at {host} returned {count} alerts.', [
                        'profile' => $profile->key,
                        'host' => $this->hostOf($endpoint),
                        'count' => count($alerts),
                    ]);

                    return $alerts;
                } catch (\Throwable $e) {
                    $item->expiresAfter(self::FAILURE_TTL_SECONDS);
                    $this->logger->warning('SIRI alerts fetch failed for profile {profile} at {host}: {error}', [
                        'profile' => $profile->key,
                        'host' => $this->hostOf($endpoint),
                        'error' => $e->getMessage(),
                    ]);

                    return [];
                }
            },
        );
    }

    private function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : 'unknown';
    }
}
