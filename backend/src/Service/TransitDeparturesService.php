<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\EntityNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @phpstan-type Candidate array{
 *     route: string,
 *     direction: int,
 *     headsign: string,
 *     trip: string,
 *     src: int,
 *     seq: int,
 *     scheduled: int,
 *     serviceDate: string,
 *     expected: int,
 *     realtime: bool,
 *     delaySeconds: int
 * }
 * @phpstan-type TripUpdate array{
 *     startDate: ?string,
 *     delay: ?int,
 *     stops: list<array{stopId: string, seq: ?int, time: ?int, delay: ?int}>
 * }
 * @phpstan-type Group array{
 *     routeId: string,
 *     lineRef: string,
 *     lineName: string,
 *     colour: string,
 *     headsign: string,
 *     directionId: int,
 *     realtime: bool,
 *     departures: list<array{
 *         at: int,
 *         inSeconds: int,
 *         realtime: bool,
 *         delaySeconds: int,
 *         headsign: string
 *     }>
 * }
 * @phpstan-type Index array{
 *     timezone: string,
 *     routes: array<string, array{ref: string, name: string, colour: string}>,
 *     serviceDays: array<string, list<string>>,
 *     sources: list<array{uuid: string}>
 * }
 */
final readonly class TransitDeparturesService
{
    private const int MAX_PER_GROUP = 2;
    private const int REALTIME_LOOKBACK_SECONDS = 900;

    public function __construct(
        #[Autowire('%app.tiles_dir%')]
        private string $tilesBaseDir,
        private TransitRealtimeService $realtime,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $realtimeUrlsBySourceUuid
     * @return array{timezone: string, serverNow: int, groups: list<Group>}
     */
    public function forStation(
        string $gameUuid,
        string $stationId,
        array $realtimeUrlsBySourceUuid = [],
        ?\DateTimeImmutable $now = null,
        bool $fullDay = false,
    ): array {
        $index = $this->loadIndex($gameUuid);
        $timezone = $this->timezone($index['timezone']);
        $localNow = ($now ?? new \DateTimeImmutable('now'))->setTimezone($timezone);
        $departures = $this->loadStationDepartures($gameUuid, $stationId);
        $originalStops = $this->loadOriginalStops($gameUuid, $stationId);

        $candidates = $this->scheduledCandidates(
            $departures,
            $index,
            $localNow,
            $realtimeUrlsBySourceUuid !== [],
            $fullDay,
        );
        $candidates = $this->applyRealtime(
            $candidates,
            $index,
            $originalStops,
            $stationId,
            $realtimeUrlsBySourceUuid,
            $localNow,
            $fullDay,
        );
        $groups = $this->group($candidates, $index['routes'], $localNow, $fullDay ? null : self::MAX_PER_GROUP);

        return [
            'timezone' => $timezone->getName(),
            'serverNow' => $localNow->getTimestamp(),
            'groups' => $groups,
        ];
    }

    /** @return Index */
    private function loadIndex(string $gameUuid): array
    {
        $path = $this->timetableDir($gameUuid) . '/meta.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new EntityNotFoundException(
                'Timetable not available for this game.',
                'transit_timetable.not_available',
            );
        }

        $routes = [];
        if (is_array($decoded['routes'] ?? null)) {
            foreach ($decoded['routes'] as $routeId => $route) {
                if (is_array($route)) {
                    $routes[(string) $routeId] = [
                        'ref' => is_string($route['ref'] ?? null) ? $route['ref'] : (string) $routeId,
                        'name' => is_string($route['name'] ?? null) ? $route['name'] : '',
                        'colour' => is_string($route['colour'] ?? null) ? $route['colour'] : '',
                    ];
                }
            }
        }

        $serviceDays = [];
        if (is_array($decoded['serviceDays'] ?? null)) {
            foreach ($decoded['serviceDays'] as $date => $services) {
                if (is_array($services)) {
                    $serviceDays[(string) $date] = array_values(array_filter($services, 'is_string'));
                }
            }
        }

        $sources = [];
        if (is_array($decoded['sources'] ?? null)) {
            foreach ($decoded['sources'] as $source) {
                if (is_array($source) && is_string($source['uuid'] ?? null)) {
                    $sources[] = ['uuid' => $source['uuid']];
                }
            }
        }

        return [
            'timezone' => is_string($decoded['timezone'] ?? null) ? $decoded['timezone'] : 'UTC',
            'routes' => $routes,
            'serviceDays' => $serviceDays,
            'sources' => $sources,
        ];
    }

    private function timezone(string $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone($name);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    /** @return list<array<mixed, mixed>> */
    private function loadStationDepartures(string $gameUuid, string $stationId): array
    {
        $path = $this->timetableDir($gameUuid) . '/' . sha1($stationId) . '.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /** @return array<int, list<string>> */
    private function loadOriginalStops(string $gameUuid, string $stationId): array
    {
        $path = $this->timetableDir($gameUuid) . '/stops.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $stationStops = is_array($decoded) ? ($decoded[$stationId] ?? null) : null;
        if (!is_array($stationStops)) {
            return [];
        }

        $result = [];
        foreach ($stationStops as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $src = $entry['src'] ?? null;
            $stopId = $entry['stopId'] ?? null;
            if (is_int($src) && is_string($stopId) && $stopId !== '') {
                $result[$src][] = $stopId;
            }
        }

        return $result;
    }

    /**
     * @param list<array<mixed, mixed>> $departures
     * @param Index $index
     * @return list<Candidate>
     */
    private function scheduledCandidates(
        array $departures,
        array $index,
        \DateTimeImmutable $now,
        bool $withRealtime,
        bool $fullDay,
    ): array {
        $cutoff = $fullDay ? 0 : $now->getTimestamp() - ($withRealtime ? self::REALTIME_LOOKBACK_SECONDS : 0);
        $timezone = $this->timezone($index['timezone']);
        $candidates = [];
        $dates = [$now->format('Y-m-d'), $now->modify('-1 day')->format('Y-m-d')];

        foreach ($dates as $dayIndex => $date) {
            $services = array_fill_keys($index['serviceDays'][$date] ?? [], true);
            if ($services === []) {
                continue;
            }
            $minimumSeconds = $fullDay && $dayIndex === 1 ? 86400 : 0;
            $midnight = new \DateTimeImmutable($date . ' 00:00:00', $timezone);
            foreach ($departures as $departure) {
                $candidate = $this->candidateFrom(
                    $departure,
                    $services,
                    $midnight,
                    $date,
                    $cutoff,
                    $minimumSeconds,
                );
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param array<mixed, mixed> $departure
     * @param array<string, true> $services
     * @return Candidate|null
     */
    private function candidateFrom(
        array $departure,
        array $services,
        \DateTimeImmutable $midnight,
        string $date,
        int $cutoff,
        int $minimumSeconds,
    ): ?array {
        $route = $departure['route'] ?? null;
        $service = $departure['service'] ?? null;
        $trip = $departure['trip'] ?? null;
        $seconds = $departure['seconds'] ?? null;
        if (!is_string($route) || $route === '' || !is_string($service) || !is_string($trip) || $trip === '') {
            return null;
        }
        if (!isset($services[$service]) || !is_int($seconds) || $seconds < $minimumSeconds) {
            return null;
        }

        $at = $this->atSeconds($midnight, $seconds);
        if ($at->getTimestamp() < $cutoff) {
            return null;
        }

        return [
            'route' => $route,
            'direction' => is_int($departure['direction'] ?? null) ? $departure['direction'] : 0,
            'headsign' => is_string($departure['headsign'] ?? null) ? $departure['headsign'] : '',
            'trip' => $trip,
            'src' => is_int($departure['src'] ?? null) ? $departure['src'] : 0,
            'seq' => is_int($departure['seq'] ?? null) ? $departure['seq'] : 0,
            'scheduled' => $at->getTimestamp(),
            'serviceDate' => $date,
            'expected' => $at->getTimestamp(),
            'realtime' => false,
            'delaySeconds' => 0,
        ];
    }

    /** GTFS seconds are wall-clock seconds from the service day's midnight, and can exceed 86400. */
    private function atSeconds(\DateTimeImmutable $midnight, int $seconds): \DateTimeImmutable
    {
        $days = intdiv($seconds, 86400);
        $remainder = $seconds % 86400;
        $date = $days > 0 ? $midnight->modify("+{$days} days") : $midnight;

        return $date->setTime(intdiv($remainder, 3600), intdiv($remainder % 3600, 60), $remainder % 60);
    }

    /**
     * @param list<Candidate> $candidates
     * @param Index $index
     * @param array<int, list<string>> $originalStops
     * @param array<string, string> $realtimeUrlsBySourceUuid
     * @return list<Candidate>
     */
    private function applyRealtime(
        array $candidates,
        array $index,
        array $originalStops,
        string $stationId,
        array $realtimeUrlsBySourceUuid,
        \DateTimeImmutable $now,
        bool $fullDay,
    ): array {
        /** @var array<int, array<string, TripUpdate>> $updatesBySource */
        $updatesBySource = [];
        foreach ($candidates as $candidate) {
            $src = $candidate['src'];
            if (array_key_exists($src, $updatesBySource)) {
                continue;
            }
            $sourceUuid = $index['sources'][$src]['uuid'] ?? '';
            $url = $realtimeUrlsBySourceUuid[$sourceUuid] ?? null;
            $updatesBySource[$src] = $url !== null ? $this->safeUpdates($sourceUuid, $url) : [];
        }

        $result = [];
        foreach ($candidates as $candidate) {
            $expected = $candidate['expected'];
            $realtime = false;
            $trip = $updatesBySource[$candidate['src']][$candidate['trip']] ?? null;
            if ($trip !== null && $this->sameServiceDate($candidate['serviceDate'], $trip['startDate'])) {
                $stop = $this->stopFor($candidate, $trip['stops'], $originalStops, $stationId);
                [$expected, $realtime] = $this->expectedAt($candidate['scheduled'], $stop, $trip['delay']);
            }
            if ($expected < $now->getTimestamp()) {
                if (!$fullDay) {
                    continue;
                }
                $expected = $candidate['scheduled'];
                $realtime = false;
            }
            $candidate['expected'] = $expected;
            $candidate['realtime'] = $realtime;
            $candidate['delaySeconds'] = $expected - $candidate['scheduled'];
            $result[] = $candidate;
        }

        return $result;
    }

    /** @return array<string, TripUpdate> */
    private function safeUpdates(string $sourceUuid, string $url): array
    {
        try {
            return $this->realtime->tripUpdates($sourceUuid, $url);
        } catch (\Throwable $e) {
            $this->logger->warning('Realtime departures unavailable: {error}', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function sameServiceDate(string $serviceDate, ?string $startDate): bool
    {
        return $startDate === null || $startDate === '' || $startDate === str_replace('-', '', $serviceDate);
    }

    /**
     * @param Candidate $candidate
     * @param list<array{stopId: string, seq: ?int, time: ?int, delay: ?int}> $stops
     * @param array<int, list<string>> $originalStops
     * @return array{stopId: string, seq: ?int, time: ?int, delay: ?int}|null
     */
    private function stopFor(array $candidate, array $stops, array $originalStops, string $stationId): ?array
    {
        $ids = array_fill_keys($originalStops[$candidate['src']] ?? [], true);
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($stops as $stop) {
            if (!isset($ids[$stop['stopId']]) && $stop['stopId'] !== $stationId) {
                continue;
            }
            $score = $stop['seq'] !== null ? abs($stop['seq'] - $candidate['seq']) : 0;
            if ($score < $bestScore) {
                $best = $stop;
                $bestScore = $score;
            }
        }

        if ($best === null && $candidate['seq'] > 0) {
            foreach ($stops as $stop) {
                if ($stop['seq'] === $candidate['seq']) {
                    return $stop;
                }
            }
        }

        return $best;
    }

    /**
     * @param array{stopId: string, seq: ?int, time: ?int, delay: ?int}|null $stop
     * @return array{int, bool}
     */
    private function expectedAt(int $scheduled, ?array $stop, ?int $tripDelay): array
    {
        if ($stop !== null && $stop['time'] !== null && $stop['time'] > 0) {
            return [$stop['time'], true];
        }
        if ($stop !== null && $stop['delay'] !== null) {
            return [$scheduled + $stop['delay'], true];
        }
        if ($tripDelay !== null) {
            return [$scheduled + $tripDelay, true];
        }

        return [$scheduled, false];
    }

    /**
     * @param list<Candidate> $candidates
     * @param array<string, array{ref: string, name: string, colour: string}> $routes
     * @return list<Group>
     */
    private function group(
        array $candidates,
        array $routes,
        \DateTimeImmutable $now,
        ?int $maxPerGroup,
    ): array {
        usort($candidates, static fn (array $a, array $b): int => $a['expected'] <=> $b['expected']);

        /** @var array<string, Group> $groups */
        $groups = [];
        $counts = [];
        foreach ($candidates as $candidate) {
            $key = $candidate['route'] . '|' . $candidate['direction'];
            if ($maxPerGroup !== null && ($counts[$key] ?? 0) >= $maxPerGroup) {
                continue;
            }
            if (!isset($groups[$key])) {
                $route = $routes[$candidate['route']] ?? [
                    'ref' => $candidate['route'],
                    'name' => '',
                    'colour' => '',
                ];
                $groups[$key] = [
                    'routeId' => $candidate['route'],
                    'lineRef' => $route['ref'],
                    'lineName' => $route['name'],
                    'colour' => $route['colour'],
                    'headsign' => $candidate['headsign'],
                    'directionId' => $candidate['direction'],
                    'realtime' => false,
                    'departures' => [],
                ];
            }
            if ($groups[$key]['headsign'] === '') {
                $groups[$key]['headsign'] = $candidate['headsign'];
            }
            $groups[$key]['realtime'] = $groups[$key]['realtime'] || $candidate['realtime'];
            $groups[$key]['departures'][] = [
                'at' => $candidate['expected'],
                'inSeconds' => max(0, $candidate['expected'] - $now->getTimestamp()),
                'realtime' => $candidate['realtime'],
                'delaySeconds' => $candidate['delaySeconds'],
                'headsign' => $candidate['headsign'],
            ];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        foreach ($groups as &$group) {
            $headsigns = [];
            foreach ($group['departures'] as $departure) {
                if ($departure['headsign'] !== '') {
                    $headsigns[$departure['headsign']] = ($headsigns[$departure['headsign']] ?? 0) + 1;
                }
            }
            if ($headsigns !== []) {
                arsort($headsigns);
                $group['headsign'] = (string) array_key_first($headsigns);
            }
        }
        unset($group);

        usort($groups, static fn (array $a, array $b): int => strnatcasecmp($a['lineRef'], $b['lineRef'])
            ?: $a['directionId'] <=> $b['directionId']);

        return $groups;
    }

    private function timetableDir(string $gameUuid): string
    {
        return $this->tilesBaseDir . '/' . $gameUuid . '/timetable';
    }
}
