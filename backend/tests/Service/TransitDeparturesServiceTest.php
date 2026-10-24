<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\EntityNotFoundException;
use App\Service\OutboundHttpFetcher;
use App\Service\OutboundUrlGuard;
use App\Service\TransitDeparturesService;
use App\Service\TransitRealtimeService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TransitDeparturesServiceTest extends TestCase
{
    private const string GAME = 'game-1';
    private const string STATION = 'S_0.0000_0.0000';

    private string $tilesDir;

    protected function setUp(): void
    {
        $this->tilesDir = sys_get_temp_dir() . '/departures-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->tilesDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tilesDir);
        parent::tearDown();
    }

    #[Test]
    public function itReturnsTheNextTwoScheduledDeparturesPerRouteAndDirection(): void
    {
        $this->writeIndex(self::GAME, [
            $this->departure('1', 13 * 3600, 'T1', 0, 'Alpha'),
            $this->departure('1', 14 * 3600, 'T2', 0, 'Alpha'),
            $this->departure('1', 15 * 3600, 'T3', 0, 'Alpha'),
            $this->departure('2', 13 * 3600 + 1800, 'T4', 1, 'Beta'),
            $this->departure('1', 11 * 3600, 'T5', 0, 'Alpha'),
        ]);

        $result = $this->service()->forStation(self::GAME, self::STATION, [], $this->now());

        self::assertSame('Europe/Paris', $result['timezone']);
        self::assertCount(2, $result['groups']);
        self::assertSame('L1', $result['groups'][0]['lineRef']);
        self::assertSame('B22E75', $result['groups'][0]['colour']);
        self::assertCount(2, $result['groups'][0]['departures']);
        self::assertSame(3600, $result['groups'][0]['departures'][0]['inSeconds']);
        self::assertFalse($result['groups'][0]['departures'][0]['realtime']);
        self::assertSame('L2', $result['groups'][1]['lineRef']);
        self::assertCount(1, $result['groups'][1]['departures']);
    }

    #[Test]
    public function itReturnsTheWholeServiceDayWhenAsked(): void
    {
        $this->writeIndex(self::GAME, [
            $this->departure('1', 11 * 3600, 'T1', 0, 'Alpha'),
            $this->departure('1', 13 * 3600, 'T2', 0, 'Alpha'),
            $this->departure('1', 14 * 3600, 'T3', 0, 'Alpha'),
            $this->departure('1', 15 * 3600, 'T4', 0, 'Alpha'),
            $this->departure('2', 13 * 3600 + 1800, 'T5', 1, 'Beta'),
        ], ['2026-10-18' => ['S1'], '2026-10-17' => ['S1']]);

        $result = $this->service()->forStation(self::GAME, self::STATION, [], $this->now(), fullDay: true);

        self::assertCount(2, $result['groups']);
        self::assertCount(4, $result['groups'][0]['departures']);
        self::assertSame(
            (new \DateTimeImmutable('2026-10-18 11:00:00', new \DateTimeZone('Europe/Paris')))->getTimestamp(),
            $result['groups'][0]['departures'][0]['at'],
        );
        self::assertSame(0, $result['groups'][0]['departures'][0]['inSeconds']);
        self::assertCount(1, $result['groups'][1]['departures']);
        self::assertSame(5400, $result['groups'][1]['departures'][0]['inSeconds']);
    }

    #[Test]
    public function itLabelsAGroupWithItsMostCommonHeadsign(): void
    {
        $this->writeIndex(self::GAME, [
            $this->departure('1', 11 * 3600, 'T1', 0, 'Borny'),
            $this->departure('1', 13 * 3600, 'T2', 0, 'Hôpital Schuman'),
            $this->departure('1', 14 * 3600, 'T3', 0, 'Hôpital Schuman'),
        ]);

        $result = $this->service()->forStation(self::GAME, self::STATION, [], $this->now(), fullDay: true);

        self::assertSame('Hôpital Schuman', $result['groups'][0]['headsign']);
        self::assertSame('Borny', $result['groups'][0]['departures'][0]['headsign']);
    }

    #[Test]
    public function itIncludesThePreviousServiceDayAfterMidnight(): void
    {
        $this->writeIndex(self::GAME, [
            $this->departure('1', 25 * 3600, 'T1', 0, 'Alpha'),
        ], ['2026-10-18' => ['S1']]);

        $result = $this->service()->forStation(
            self::GAME,
            self::STATION,
            [],
            new \DateTimeImmutable('2026-10-19 00:30:00', new \DateTimeZone('Europe/Paris')),
        );

        self::assertCount(1, $result['groups']);
        self::assertSame(1800, $result['groups'][0]['departures'][0]['inSeconds']);
    }

    #[Test]
    public function itThrowsWhenTheGameHasNoTimetableIndex(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->service()->forStation('missing-game', self::STATION, [], $this->now());
    }

    #[Test]
    public function itReturnsNoGroupsForAStationWithNoDepartureFile(): void
    {
        $this->writeIndex(self::GAME, []);

        $result = $this->service()->forStation(self::GAME, 'unknown-station', [], $this->now());

        self::assertSame([], $result['groups']);
    }

    #[Test]
    public function itUsesARealtimeStopTimeAndReportsTheDelay(): void
    {
        $this->writeIndex(self::GAME, [
            $this->departure('1', 13 * 3600, 'T1', 0, 'Alpha'),
        ]);
        $scheduled = (new \DateTimeImmutable('2026-10-18 13:00:00', new \DateTimeZone('Europe/Paris')))->getTimestamp();
        $realtime = $this->realtimeService(new MockHttpClient(new MockResponse(json_encode([
            'headerTimestamp' => time(),
            'type' => 'trip_update',
            'trips' => [
                'T1' => [
                    'startDate' => '20261018',
                    'stops' => [['stopId' => 'orig-1', 'seq' => 1, 'time' => $scheduled + 120]],
                ],
            ],
        ], JSON_THROW_ON_ERROR))));

        $result = $this->service($realtime)->forStation(
            self::GAME,
            self::STATION,
            ['source-1' => 'https://8.8.8.8/rt'],
            $this->now(),
        );

        $departure = $result['groups'][0]['departures'][0];
        self::assertTrue($departure['realtime']);
        self::assertSame(120, $departure['delaySeconds']);
        self::assertSame($scheduled + 120, $departure['at']);
        self::assertTrue($result['groups'][0]['realtime']);
    }

    #[Test]
    public function itFallsBackToTheScheduledTimeWhenTheRealtimeFeedIsStale(): void
    {
        $this->writeIndex(self::GAME, [
            $this->departure('1', 13 * 3600, 'T1', 0, 'Alpha'),
        ]);
        $realtime = $this->realtimeService(new MockHttpClient(new MockResponse(json_encode([
            'headerTimestamp' => time() - 400,
            'type' => 'trip_update',
            'trips' => ['T1' => ['stops' => [['stopId' => 'orig-1', 'seq' => 1, 'time' => 1]]]],
        ], JSON_THROW_ON_ERROR))));

        $result = $this->service($realtime)->forStation(
            self::GAME,
            self::STATION,
            ['source-1' => 'https://8.8.8.8/rt'],
            $this->now(),
        );

        self::assertFalse($result['groups'][0]['departures'][0]['realtime']);
        self::assertSame(3600, $result['groups'][0]['departures'][0]['inSeconds']);
    }

    #[Test]
    public function itCachesARealtimeFeedAcrossStationReads(): void
    {
        $this->writeIndex(self::GAME, [
            $this->departure('1', 13 * 3600, 'T1', 0, 'Alpha'),
        ]);
        $calls = 0;
        $realtime = $this->realtimeService(new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(json_encode([
                'headerTimestamp' => time(),
                'type' => 'trip_update',
                'trips' => [],
            ], JSON_THROW_ON_ERROR));
        }));
        $service = $this->service($realtime);

        $service->forStation(self::GAME, self::STATION, ['source-1' => 'https://8.8.8.8/rt'], $this->now());
        $service->forStation(self::GAME, self::STATION, ['source-1' => 'https://8.8.8.8/rt'], $this->now());

        self::assertSame(1, $calls);
    }

    #[Test]
    public function itFallsBackToTheScheduleWhenTheRealtimeFetchFails(): void
    {
        $this->writeIndex(self::GAME, [
            $this->departure('1', 13 * 3600, 'T1', 0, 'Alpha'),
        ]);
        $realtime = $this->realtimeService(new MockHttpClient(
            static fn (): MockResponse => new MockResponse('', ['http_code' => 503]),
        ));

        $result = $this->service($realtime)->forStation(
            self::GAME,
            self::STATION,
            ['source-1' => 'https://8.8.8.8/rt'],
            $this->now(),
        );

        self::assertFalse($result['groups'][0]['departures'][0]['realtime']);
    }

    private function service(?TransitRealtimeService $realtime = null): TransitDeparturesService
    {
        return new TransitDeparturesService(
            $this->tilesDir,
            $realtime ?? $this->realtimeService(new MockHttpClient(static fn (): MockResponse => new MockResponse(''))),
            new NullLogger(),
        );
    }

    private function realtimeService(MockHttpClient $client): TransitRealtimeService
    {
        return new TransitRealtimeService(
            new OutboundHttpFetcher($client, new OutboundUrlGuard(), new NullLogger()),
            new ArrayAdapter(),
            new NullLogger(),
            ['/bin/cat'],
        );
    }

    /** @return array{route: string, service: string, seconds: int, trip: string, direction: int, headsign: string} */
    private function departure(string $route, int $seconds, string $trip, int $direction, string $headsign): array
    {
        return [
            'route' => $route,
            'service' => 'S1',
            'seconds' => $seconds,
            'trip' => $trip,
            'direction' => $direction,
            'headsign' => $headsign,
        ];
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-18 12:00:00', new \DateTimeZone('Europe/Paris'));
    }

    /**
     * @param list<array<string, mixed>> $departures
     * @param array<string, list<string>>|null $serviceDays
     */
    private function writeIndex(string $gameUuid, array $departures, ?array $serviceDays = null): void
    {
        $dir = $this->tilesDir . '/' . $gameUuid . '/timetable';
        (new Filesystem())->mkdir($dir);
        file_put_contents($dir . '/meta.json', json_encode([
            'timezone' => 'Europe/Paris',
            'sources' => [['uuid' => 'source-1']],
            'routes' => [
                '1' => ['ref' => 'L1', 'name' => 'Line One', 'colour' => 'B22E75'],
                '2' => ['ref' => 'L2', 'name' => 'Line Two', 'colour' => '112233'],
            ],
            'serviceDays' => $serviceDays ?? ['2026-10-18' => ['S1']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents(
            $dir . '/stops.json',
            json_encode([self::STATION => [['src' => 0, 'stopId' => 'orig-1']]], JSON_THROW_ON_ERROR),
        );
        $rows = array_map(static fn (array $row): array => $row + ['src' => 0, 'seq' => 1], $departures);
        file_put_contents(
            $dir . '/' . sha1(self::STATION) . '.json',
            json_encode($rows, JSON_THROW_ON_ERROR),
        );
    }
}
