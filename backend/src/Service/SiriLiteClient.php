<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\RealtimeSelection;
use App\Enum\RealtimeProvider;
use App\Enum\SiriService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Fetches SIRI Lite visits for a station board.
 *
 * Every failure resolves to an empty list, which leaves the scheduled times in place. Logs never
 * carry tokens or response bodies.
 */
final readonly class SiriLiteClient
{
    private const int STOP_MONITORING_TTL_SECONDS = 25;
    private const int ESTIMATED_TIMETABLE_TTL_SECONDS = 30;
    private const int FAILURE_TTL_SECONDS = 60;
    private const float TOTAL_BUDGET_SECONDS = 4.0;
    private const int MAX_STOP_MONITORING_REFS = 4;
    private const int MAX_ESTIMATED_TIMETABLE_LINES = 8;
    private const int MAX_BYTES = 32 * 1024 * 1024;
    private const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private OutboundHttpFetcher $fetcher,
        private SiriProfileRegistry $profiles,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $stopIds
     * @param list<string> $routeIds
     *
     * @return list<SiriVisit>
     */
    public function visits(RealtimeSelection $selection, array $stopIds, array $routeIds): array
    {
        $profile = $selection->provider === RealtimeProvider::SiriLite->value
            ? $this->profiles->find((string) $selection->profile)
            : null;
        if ($profile === null || !$profile->available) {
            return [];
        }

        $deadline = microtime(true) + self::TOTAL_BUDGET_SECONDS;

        return match ($profile->service) {
            SiriService::StopMonitoring => $this->stopMonitoringVisits($profile, $stopIds, $deadline),
            SiriService::EstimatedTimetable => $this->estimatedTimetableVisits(
                $profile,
                $stopIds,
                $routeIds,
                $deadline,
            ),
        };
    }

    /**
     * @param list<string> $stopIds
     *
     * @return list<SiriVisit>
     */
    private function stopMonitoringVisits(SiriProfile $profile, array $stopIds, float $deadline): array
    {
        foreach ($this->refs($stopIds, self::MAX_STOP_MONITORING_REFS) as $stopId) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $query = $profile->query;
            $query['MonitoringRef'] = $profile->stopRef($stopId) ?? $stopId;
            $visits = $this->fetch($profile, $query, true, $deadline);
            if ($visits !== []) {
                return $visits;
            }
        }

        return [];
    }

    /**
     * @param list<string> $stopIds
     * @param list<string> $routeIds
     *
     * @return list<SiriVisit>
     */
    private function estimatedTimetableVisits(
        SiriProfile $profile,
        array $stopIds,
        array $routeIds,
        float $deadline,
    ): array {
        $uniqueRoutes = $this->refs($routeIds, PHP_INT_MAX);
        if ($profile->lineRefTemplate !== null && count($uniqueRoutes) > self::MAX_ESTIMATED_TIMETABLE_LINES) {
            $this->logger->warning(
                'SIRI estimated-timetable fan-out capped at {max} lines for profile {profile}.',
                ['max' => self::MAX_ESTIMATED_TIMETABLE_LINES, 'profile' => $profile->key],
            );
        }
        $lineRefs = $profile->lineRefTemplate === null
            ? [null]
            : array_slice($uniqueRoutes, 0, self::MAX_ESTIMATED_TIMETABLE_LINES);
        $visits = [];
        foreach ($lineRefs as $lineRef) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $query = $profile->query;
            if ($lineRef !== null) {
                $query['LineRef'] = $profile->lineRef($lineRef) ?? $lineRef;
            } elseif ($profile->stopRefTemplate !== null) {
                $firstStop = $this->refs($stopIds, 1)[0] ?? null;
                if ($firstStop !== null) {
                    $query['MonitoringRef'] = $profile->stopRef($firstStop) ?? $firstStop;
                }
            }
            $visits = [...$visits, ...$this->fetch($profile, $query, false, $deadline)];
        }

        return $this->filterByStops($visits, $stopIds);
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<SiriVisit>
     */
    private function fetch(SiriProfile $profile, array $query, bool $stopMonitoring, float $deadline): array
    {
        $url = $profile->endpoint . (str_contains($profile->endpoint, '?') ? '&' : '?') . http_build_query($query);
        $ttl = $stopMonitoring ? self::STOP_MONITORING_TTL_SECONDS : self::ESTIMATED_TIMETABLE_TTL_SECONDS;

        return $this->cache->get(
            'siri_' . $profile->key . '_' . md5($url),
            function (ItemInterface $item) use ($profile, $url, $ttl, $stopMonitoring, $deadline): array {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0.0) {
                    $item->expiresAfter(self::FAILURE_TTL_SECONDS);

                    return [];
                }

                try {
                    $visits = $this->parse(
                        $this->fetchBody($profile, $url, min(self::TIMEOUT_SECONDS, $remaining)),
                        $stopMonitoring,
                    );
                    $item->expiresAfter($ttl);

                    return $visits;
                } catch (\Throwable $e) {
                    $item->expiresAfter(self::FAILURE_TTL_SECONDS);
                    $this->logger->warning('SIRI fetch failed for profile {profile} at {host}: {error}', [
                        'profile' => $profile->key,
                        'host' => $this->hostOf($profile->endpoint),
                        'error' => $e->getMessage(),
                    ]);

                    return [];
                }
            },
        );
    }

    private function fetchBody(SiriProfile $profile, string $url, float $timeoutSeconds): string
    {
        return $this->fetcher->fetchToString(
            $url,
            self::MAX_BYTES,
            $timeoutSeconds,
            $timeoutSeconds,
            ['Accept' => 'application/json'] + $profile->authHeaders,
        );
    }

    /** @return list<SiriVisit> */
    private function parse(string $body, bool $stopMonitoring): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }
        $siri = is_array($decoded['Siri'] ?? null) ? $decoded['Siri'] : [];
        $delivery = $siri['ServiceDelivery'] ?? $decoded['ServiceDelivery'] ?? null;
        if (!is_array($delivery)) {
            return [];
        }

        return $stopMonitoring ? $this->parseStopMonitoring($delivery) : $this->parseEstimatedTimetable($delivery);
    }

    /**
     * @param array<mixed> $delivery
     *
     * @return list<SiriVisit>
     */
    private function parseStopMonitoring(array $delivery): array
    {
        $visits = [];
        foreach ($this->many($delivery['StopMonitoringDelivery'] ?? null) as $deliveryItem) {
            if (!is_array($deliveryItem)) {
                continue;
            }
            foreach ($this->many($deliveryItem['MonitoredStopVisit'] ?? null) as $stopVisit) {
                if (!is_array($stopVisit)) {
                    continue;
                }
                $journey = $stopVisit['MonitoredVehicleJourney'] ?? null;
                if (!is_array($journey)) {
                    continue;
                }
                $call = is_array($journey['MonitoredCall'] ?? null) ? $journey['MonitoredCall'] : [];
                $visit = $this->visit($journey, $call, $stopVisit['MonitoringRef'] ?? null);
                if ($visit !== null) {
                    $visits[] = $visit;
                }
            }
        }

        return $visits;
    }

    /**
     * @param array<mixed> $delivery
     *
     * @return list<SiriVisit>
     */
    private function parseEstimatedTimetable(array $delivery): array
    {
        $visits = [];
        foreach ($this->many($delivery['EstimatedTimetableDelivery'] ?? null) as $deliveryItem) {
            if (!is_array($deliveryItem)) {
                continue;
            }
            $frames = $deliveryItem['EstimatedJourneyVersionFrame']
                ?? $deliveryItem['EstimatedTimetableVersionFrame']
                ?? null;
            foreach ($this->many($frames) as $frame) {
                if (!is_array($frame)) {
                    continue;
                }
                foreach ($this->many($frame['EstimatedVehicleJourney'] ?? null) as $journey) {
                    if (!is_array($journey)) {
                        continue;
                    }
                    $calls = $journey['EstimatedCalls'] ?? null;
                    $calls = is_array($calls) ? ($calls['EstimatedCall'] ?? $calls) : null;
                    foreach ($this->many($calls) as $call) {
                        if (!is_array($call)) {
                            continue;
                        }
                        $visit = $this->visit($journey, $call);
                        if ($visit !== null) {
                            $visits[] = $visit;
                        }
                    }
                }
            }
        }

        return $visits;
    }

    /**
     * @param array<mixed> $journey
     * @param array<mixed> $call
     */
    private function visit(array $journey, array $call, mixed $fallbackStopRef = null): ?SiriVisit
    {
        $lineCode = $this->ref($journey['LineRef'] ?? null);
        $stopCode = $this->ref($call['StopPointRef'] ?? null);
        if ($stopCode === '') {
            $stopCode = $this->ref($fallbackStopRef);
        }
        $aimedAt = $this->timestamp($call['AimedDepartureTime'] ?? null)
            ?? $this->timestamp($call['AimedArrivalTime'] ?? null);
        if ($lineCode === '' || $stopCode === '' || $aimedAt === null) {
            return null;
        }

        return new SiriVisit(
            lineCode: $lineCode,
            stopCode: $stopCode,
            tripRef: $this->tripRef($journey),
            aimedAt: $aimedAt,
            expectedAt: $this->timestamp($call['ExpectedDepartureTime'] ?? null)
                ?? $this->timestamp($call['ExpectedArrivalTime'] ?? null),
            cancelled: $this->cancelled($journey, $call),
            stopSequence: $this->sequence($call['Order'] ?? $call['StopSequence'] ?? null),
            destination: $this->destination($journey, $call),
        );
    }

    /**
     * @param array<mixed> $journey
     * @param array<mixed> $call
     */
    private function destination(array $journey, array $call): ?string
    {
        $candidates = [
            $call['DestinationName'] ?? null,
            $journey['DestinationName'] ?? null,
            $call['DestinationDisplay'] ?? null,
            $call['DestinationShortName'] ?? null,
            $journey['DestinationShortName'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $text = $this->destinationText($candidate);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function destinationText(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        return is_array($value) ? $this->listDestinationText($value) : '';
    }

    /** @param array<mixed> $value */
    private function listDestinationText(array $value): string
    {
        if (is_string($value['value'] ?? null)) {
            return trim($value['value']);
        }
        foreach ($value as $entry) {
            $text = $this->destinationText($entry);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function sequence(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        $text = $this->ref($value);

        return preg_match('/^\d+$/', $text) === 1 ? (int) $text : null;
    }

    /** @param array<mixed> $journey */
    private function tripRef(array $journey): ?string
    {
        $framed = is_array($journey['FramedVehicleJourneyRef'] ?? null) ? $journey['FramedVehicleJourneyRef'] : [];
        $candidates = [
            $framed['DatedVehicleJourneyRef'] ?? null,
            $framed['DatedVehicleJourneySAERef'] ?? null,
            $journey['DatedVehicleJourneyRef'] ?? null,
            $journey['VehicleJourneyRef'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $ref = $this->ref($candidate);
            if ($ref !== '') {
                return $ref;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $journey
     * @param array<mixed> $call
     */
    private function cancelled(array $journey, array $call): bool
    {
        $statuses = [
            $call['DepartureStatus'] ?? null,
            $call['ArrivalStatus'] ?? null,
            $journey['JourneyStatus'] ?? null,
        ];
        foreach ($statuses as $status) {
            if (in_array(strtolower($this->ref($status)), ['cancelled', 'canceled'], true)) {
                return true;
            }
        }

        return ($call['Cancellation'] ?? false) === true
            || ($journey['Cancellation'] ?? false) === true;
    }

    private function ref(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_array($value) && is_string($value['value'] ?? null)) {
            return trim($value['value']);
        }

        return '';
    }

    private function timestamp(mixed $value): ?int
    {
        $text = $this->ref($value);
        if ($text === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($text)->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /** @return list<mixed> */
    private function many(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }

    /**
     * @param list<string> $refs
     *
     * @return list<string>
     */
    private function refs(array $refs, int $limit): array
    {
        $unique = [];
        foreach ($refs as $ref) {
            $trimmed = trim($ref);
            if ($trimmed !== '' && !in_array($trimmed, $unique, true)) {
                $unique[] = $trimmed;
            }
        }

        return array_slice($unique, 0, $limit);
    }

    /**
     * @param list<SiriVisit> $visits
     * @param list<string> $stopIds
     *
     * @return list<SiriVisit>
     */
    private function filterByStops(array $visits, array $stopIds): array
    {
        $codes = [];
        foreach ($stopIds as $stopId) {
            $code = SiriRef::normalized($stopId);
            if ($code !== '') {
                $codes[$code] = true;
            }
        }
        if ($codes === []) {
            return [];
        }

        return array_values(array_filter(
            $visits,
            static fn (SiriVisit $visit): bool => isset($codes[SiriRef::normalized($visit->stopCode)]),
        ));
    }

    private function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : 'unknown';
    }
}
