<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\HidingZone;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Entity\RoundStreetNetwork;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Enum\StreetNetworkStatus;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Exception\IdentityRequiredException;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Repository\RoundStreetNetworkRepository;
use App\Service\HiderGuard;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Service\OverpassHttpClient;
use App\Service\StreetNetworkService;
use App\StreetNetworkRules;
use App\Tests\Support\AccountFactory;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(StreetNetworkService::class)]
final class StreetNetworkServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    private const string ONE_WAY = '{"version":0.6,"elements":[{"type":"way","id":1,'
        . '"tags":{"highway":"residential"},"geometry":['
        . '{"lat":52.52,"lon":13.405},{"lat":52.521,"lon":13.406}]}]}';

    private const string TRUNCATED = '{"version":0.6,"elements":[';

    /** Three integer digits of longitude and two of latitude make every trimmed pair as long as one can be. */
    private const float OVERSIZE_LNG = -113.10001;

    private const float OVERSIZE_LAT = -52.10001;

    private const float OVERSIZE_RADIUS = 5000.0;

    private Side $subscriberSide = Side::Hider;

    private ?Player $subscriber = null;

    private ?RequestStack $requestStack = null;

    #[Test]
    public function itSkipsASmallGameWhereNeitherTracedStreetTargetExists(): void
    {
        $round = self::round(GameSize::Small, RoundStatus::Hiding);
        $networks = $this->warmableMock();
        $networks->expects(self::never())->method('findOneByRound');
        $networks->expects(self::never())->method('save');

        $this->service($networks)->enqueueForZone($round, self::zone($round, 13.405, 500.0));
    }

    /**
     * @return array<string, array{status: RoundStatus}>
     */
    public static function inactiveStatuses(): array
    {
        return [
            'lobby' => ['status' => RoundStatus::Lobby],
            'ended' => ['status' => RoundStatus::Ended],
        ];
    }

    #[DataProvider('inactiveStatuses')]
    #[Test]
    public function itSkipsARoundThatIsNeitherHidingNorSeeking(RoundStatus $status): void
    {
        $round = self::round(GameSize::Medium, $status);
        $networks = $this->warmableMock();
        $networks->expects(self::never())->method('save');

        $this->service($networks)->enqueueForZone($round, self::zone($round, 13.405, 500.0));
    }

    #[Test]
    public function itCreatesAPendingRowForARoundThatHasNone(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $networks = $this->warmableMock();
        $networks->method('findOneByRound')->willReturn(null);
        $networks->expects(self::never())->method('save');
        $networks->expects(self::once())->method('insertPendingForRound')->with(
            $round,
            self::callback(static function (Point $center): bool {
                self::assertSame(13.405, $center->getLongitude());
                self::assertSame(52.52, $center->getLatitude());

                return true;
            }),
            500.0,
        );

        $this->service($networks)->enqueueForZone($round, self::zone($round, 13.405, 500.0));
    }

    #[Test]
    public function itResetsAReadyRowWhenTheZoneCentreHasMoved(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Seeking);
        $existing = self::readyNetwork($round, 13.405, 500.0);
        $networks = $this->warmableMock();
        $networks->method('findOneByRound')->willReturn($existing);
        $networks->expects(self::once())->method('save')->with($existing);

        $this->service($networks)->enqueueForZone($round, self::zone($round, 13.41, 500.0));

        self::assertSame(StreetNetworkStatus::Pending, $existing->getStatus());
        self::assertSame(13.41, $existing->getCenter()->getLongitude());
        self::assertSame(500.0, $existing->getRadiusMeters());
        self::assertNull($existing->getPayload());
        self::assertSame(0, $existing->getWayCount());
        self::assertSame(0, $existing->getAttempts());
        self::assertNull($existing->getFetchedAt());
    }

    /** A radius card resizes the zone, so the network fetched for the old radius no longer serves. */
    #[Test]
    public function itResetsAReadyRowWhenTheRadiusHasChanged(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Seeking);
        $existing = self::readyNetwork($round, 13.405, 500.0);
        $networks = $this->warmableMock();
        $networks->method('findOneByRound')->willReturn($existing);
        $networks->expects(self::once())->method('save')->with($existing);

        $this->service($networks)->enqueueForZone($round, self::zone($round, 13.405, 750.0));

        self::assertSame(StreetNetworkStatus::Pending, $existing->getStatus());
        self::assertSame(750.0, $existing->getRadiusMeters());
        self::assertNull($existing->getPayload());
    }

    #[Test]
    public function itLeavesAReadyRowAloneWhenTheZoneHasNotMovedAMetre(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Seeking);
        $existing = self::readyNetwork($round, 13.405, 500.0);
        $networks = $this->warmableMock();
        $networks->method('findOneByRound')->willReturn($existing);
        $networks->expects(self::never())->method('save');

        $this->service($networks)->enqueueForZone($round, self::zone($round, 13.4050005, 500.0));

        self::assertSame(StreetNetworkStatus::Ready, $existing->getStatus());
        self::assertNotNull($existing->getPayload());
    }

    #[Test]
    public function itFetchesTrimsAndFlipsToReady(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);
        $networks = $this->createMock(RoundStreetNetworkRepository::class);
        $networks->expects(self::once())->method('acquireWarmLock')->with($round)->willReturn(true);
        $networks->expects(self::once())->method('releaseWarmLock')->with($round);
        $networks->expects(self::once())->method('save')->with($network);

        $response = new MockResponse(self::ONE_WAY);
        $this->service($networks, new MockHttpClient($response))->warm($network);

        self::assertSame(StreetNetworkStatus::Ready, $network->getStatus());
        self::assertSame(1, $network->getWayCount());
        self::assertNotNull($network->getFetchedAt());
        self::assertSame(0, $network->getAttempts());

        $payload = $network->getPayload();
        self::assertNotNull($payload);
        self::assertCount(1, $payload);
        self::assertSame('residential', $payload[0]['class']);
        self::assertSame([[13.405, 52.52], [13.406, 52.521]], $payload[0]['coordinates']);
        self::assertSame([], $payload[0]['junctionIndices']);
    }

    #[Test]
    public function itQueriesOverpassForTheZoneBboxPlusTheMarginExcludingUnbuiltHighways(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);

        $response = new MockResponse(self::ONE_WAY);
        $this->service($this->warmableStub(), new MockHttpClient($response))
            ->warm($network);

        $body = $response->getRequestOptions()['body'] ?? null;
        self::assertIsString($body);
        $query = urldecode($body);
        self::assertStringContainsString('[out:json][timeout:90];', $query);
        self::assertStringContainsString('52.514161,13.395404,52.525839,13.414596', $query);
        self::assertStringContainsString(
            'way["highway"]["highway"!~"^(proposed|construction|raceway|bus_guideway|escape)$"]',
            $query,
        );
        self::assertStringContainsString('out geom;', $query);
    }

    #[Test]
    public function itCountsAMalformedResponseAgainstAttemptsAndStaysPending(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);
        $networks = $this->warmableMock();
        $networks->expects(self::once())->method('releaseWarmLock')->with($round);
        $networks->expects(self::once())->method('save')->with($network);

        $this->service($networks, new MockHttpClient(new MockResponse(self::TRUNCATED)))->warm($network);

        self::assertSame(1, $network->getAttempts());
        self::assertSame(StreetNetworkStatus::Pending, $network->getStatus());
        self::assertNull($network->getPayload());
    }

    #[Test]
    public function itGivesUpAsUnavailableOnTheLastAllowedAttempt(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);
        $network->setAttempts(StreetNetworkRules::MAX_WARM_ATTEMPTS - 1);

        $this->service(
            $this->warmableStub(),
            new MockHttpClient(new MockResponse(self::TRUNCATED)),
        )->warm($network);

        self::assertSame(StreetNetworkRules::MAX_WARM_ATTEMPTS, $network->getAttempts());
        self::assertSame(StreetNetworkStatus::Unavailable, $network->getStatus());
    }

    #[Test]
    public function itCountsATrimmedPayloadOverTheCapAgainstAttemptsAndGivesUpAtTheLimit(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $oversize = self::oversizeOverpassResponse();

        $first = self::oversizeNetwork($round);
        $this->service(
            $this->warmableStub(),
            new MockHttpClient(new MockResponse($oversize)),
        )->warm($first);

        self::assertSame(1, $first->getAttempts());
        self::assertSame(StreetNetworkStatus::Pending, $first->getStatus());
        self::assertNull($first->getPayload());

        $last = self::oversizeNetwork($round);
        $last->setAttempts(StreetNetworkRules::MAX_WARM_ATTEMPTS - 1);
        $this->service(
            $this->warmableStub(),
            new MockHttpClient(new MockResponse($oversize)),
        )->warm($last);

        self::assertSame(StreetNetworkStatus::Unavailable, $last->getStatus());
        self::assertNull($last->getPayload());
    }

    /**
     * @return array<string, array{overpassJson: string}>
     */
    public static function answersWithNoUsableGeometry(): array
    {
        return [
            'every way outside the bbox' => ['overpassJson' => '{"version":0.6,"elements":[{"type":"way",'
                . '"id":1,"tags":{"highway":"residential"},'
                . '"geometry":[{"lat":48.85,"lon":2.35},{"lat":48.851,"lon":2.351}]}]}'],
            'nothing but nodes' => ['overpassJson' => '{"version":0.6,"elements":'
                . '[{"type":"node","id":1,"lat":52.52,"lon":13.405}]}'],
        ];
    }

    /**
     * A ready row is never refetched, so a zero-way answer cached as ready would leave the hider believing
     * snapping works for the rest of the round.
     */
    #[DataProvider('answersWithNoUsableGeometry')]
    #[Test]
    public function itCountsAnAnswerWithNoUsableGeometryAgainstAttemptsInsteadOfCachingItReady(
        string $overpassJson,
    ): void {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);
        $networks = $this->warmableMock();
        $networks->expects(self::once())->method('save')->with($network);

        $this->service($networks, new MockHttpClient(new MockResponse($overpassJson)))->warm($network);

        self::assertSame(1, $network->getAttempts());
        self::assertSame(StreetNetworkStatus::Pending, $network->getStatus());
        self::assertNull($network->getPayload());
        self::assertSame(0, $network->getWayCount());
        self::assertNull($network->getFetchedAt());
    }

    /**
     * A radius card or a moved station resets the row while the fetch is in flight, and the fetched geometry
     * belongs to the old bbox: saving it would leave the new centre matching the old ways forever.
     */
    #[Test]
    public function itDiscardsAFetchWhoseZoneMovedWhileItWasRunning(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);

        $reads = 0;
        $networks = $this->warmableMock();
        $networks->expects(self::exactly(2))->method('refresh')->willReturnCallback(
            static function (RoundStreetNetwork $row) use (&$reads): void {
                ++$reads;
                if ($reads === 2) {
                    $row->setCenter(new Point(13.5, 52.52));
                }
            },
        );
        $networks->expects(self::never())->method('save');

        $this->service($networks, new MockHttpClient(new MockResponse(self::ONE_WAY)))->warm($network);

        self::assertSame(StreetNetworkStatus::Pending, $network->getStatus());
        self::assertNull($network->getPayload());
        self::assertSame(0, $network->getAttempts());
    }

    /** A failure must never escape, so a dropped connection must not corrupt the row either. */
    #[Test]
    public function itSwallowsAnUntypedFailureWithoutTouchingTheAttemptCount(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);

        $networks = $this->warmableMock();
        $networks->method('refresh')->willThrowException(new \LogicException('connection lost'));
        $networks->expects(self::once())->method('releaseWarmLock')->with($round);
        $networks->expects(self::never())->method('save');

        $this->service($networks)->warm($network);

        self::assertSame(0, $network->getAttempts());
        self::assertSame(StreetNetworkStatus::Pending, $network->getStatus());
    }

    #[Test]
    public function itFillsTheRowTheUuidNamesAndIgnoresAUuidThatIsGone(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);

        $networks = $this->createMock(RoundStreetNetworkRepository::class);
        $networks->method('findOneByUuid')->willReturnMap([
            [$network->getUuid(), $network],
            ['00000000-0000-0000-0000-000000000000', null],
        ]);
        $networks->expects(self::once())->method('acquireWarmLock')->with($round)->willReturn(true);

        $service = $this->service($networks, new MockHttpClient(new MockResponse(self::ONE_WAY)));
        $service->fill('00000000-0000-0000-0000-000000000000');
        $service->fill($network->getUuid());

        self::assertSame(StreetNetworkStatus::Ready, $network->getStatus());
    }

    /** The tick only spawns fetches, so retiring a finished round is the one thing it does itself. */
    #[Test]
    public function itRetiresOnlyTheRowOfARoundThatIsNoLongerRunning(): void
    {
        $running = self::round(GameSize::Medium, RoundStatus::Seeking);
        $finished = self::round(GameSize::Medium, RoundStatus::Ended);
        $networks = $this->warmableMock();
        $networks->expects(self::once())->method('save');

        $service = $this->service($networks);

        self::assertFalse($service->retireIfFinished(
            new RoundStreetNetwork($running, new Point(13.405, 52.52), 500.0),
        ));
        self::assertTrue($service->retireIfFinished(
            new RoundStreetNetwork($finished, new Point(13.405, 52.52), 500.0),
        ));
    }

    #[Test]
    public function itMarksARoundThatIsNoLongerRunningUnavailableWithoutFetching(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Ended);
        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);
        $networks = $this->warmableMock();
        $networks->expects(self::once())->method('save')->with($network);
        $networks->expects(self::once())->method('releaseWarmLock')->with($round);

        $httpClient = new MockHttpClient();
        $this->service($networks, $httpClient)->warm($network);

        self::assertSame(StreetNetworkStatus::Unavailable, $network->getStatus());
        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertSame(0, $network->getAttempts());
    }

    /** The row is pending in memory and only the in-lock re-read reveals that another worker finished it. */
    #[Test]
    public function itLeavesARowAnotherWorkerAlreadyMovedAlone(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);
        $networks = $this->warmableMock();
        $networks->expects(self::once())->method('refresh')->with($network)->willReturnCallback(
            static function (RoundStreetNetwork $row): void {
                $row->setStatus(StreetNetworkStatus::Ready);
            },
        );
        $networks->expects(self::once())->method('releaseWarmLock')->with($round);
        $networks->expects(self::never())->method('save');

        $httpClient = new MockHttpClient();
        $this->service($networks, $httpClient)->warm($network);

        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertSame(StreetNetworkStatus::Ready, $network->getStatus());
    }

    #[Test]
    public function itRefusesToHandTheNetworkToAnythingButAHiderToken(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);

        $this->withSubscriberToken('not-a-token');

        $service = $this->service(
            $this->warmableStub(),
            null,
            $rounds,
        );

        $this->expectException(IdentityRequiredException::class);
        $this->expectExceptionMessage('invalid or expired');

        $service->forSubscriber($round->getUuid());
    }

    /**
     * The token is minted from the side held at mint time and outlives it by 12 hours, so a hider who
     * switched to seeker in the lobby, or was swapped into the next round, still holds one naming the hider
     * topic. The side has to come from the round's membership instead.
     */
    #[Test]
    public function itRefusesATokenWhoseHolderIsASeekerOnThisRound(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Seeking);
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $token = $this->hiderToken($round->getGame());
        $this->subscriberSide = Side::Seeker;
        $this->withSubscriberToken($token);

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Only a hider on this round may read that.');

        $this->service($this->warmableStub(), null, $rounds)->forSubscriber($round->getUuid());
    }

    #[Test]
    public function itRefusesAnUnknownRound(): void
    {
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->service($this->warmableStub(), null, $rounds)
            ->forSubscriber('00000000-0000-0000-0000-000000000000');
    }

    /** The lazy-enqueue path: a hider whose row was never enqueued still gets one on the first read. */
    #[Test]
    public function itEnqueuesAndReportsNothingYetWhenTheRowNoLongerMatchesTheZone(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn(self::zone($round, 13.41, 500.0));

        $stale = self::readyNetwork($round, 13.405, 500.0);
        $networks = $this->warmableMock();
        $networks->method('findOneByRound')->willReturn($stale);
        $networks->expects(self::once())->method('save')->with($stale);

        $this->withSubscriberToken($this->hiderToken($round->getGame()));
        $served = $this->service($networks, null, $rounds, $zones)
            ->forSubscriber($round->getUuid());

        self::assertNull($served);
        self::assertSame(StreetNetworkStatus::Pending, $stale->getStatus());
        self::assertSame(13.41, $stale->getCenter()->getLongitude());
    }

    #[Test]
    public function itReportsNothingYetAndCreatesNoRowBeforeAZoneExists(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn(null);

        $networks = $this->warmableMock();
        $networks->expects(self::never())->method('save');

        $this->withSubscriberToken($this->hiderToken($round->getGame()));
        $served = $this->service($networks, null, $rounds, $zones)
            ->forSubscriber($round->getUuid());

        self::assertNull($served);
    }

    /**
     * A later tick spawns another child for a row that is still pending, so the loser of the lock must drop
     * the row instead of waiting out the live fetch with a connection of its own.
     */
    #[Test]
    public function itDropsARowWhoseWarmLockAnotherWorkerHolds(): void
    {
        $round = self::round(GameSize::Medium, RoundStatus::Hiding);
        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);
        $networks = $this->createMock(RoundStreetNetworkRepository::class);
        $networks->expects(self::once())->method('acquireWarmLock')->with($round)->willReturn(false);
        $networks->expects(self::never())->method('releaseWarmLock');
        $networks->expects(self::never())->method('refresh');
        $networks->expects(self::never())->method('save');

        $httpClient = new MockHttpClient();
        $this->service($networks, $httpClient)->warm($network);

        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertSame(StreetNetworkStatus::Pending, $network->getStatus());
        self::assertSame(0, $network->getAttempts());
    }

    /**
     * @return RoundStreetNetworkRepository&MockObject
     */
    private function warmableMock(): RoundStreetNetworkRepository
    {
        $networks = $this->createMock(RoundStreetNetworkRepository::class);
        $networks->method('acquireWarmLock')->willReturn(true);

        return $networks;
    }

    private function warmableStub(): RoundStreetNetworkRepository
    {
        $networks = $this->createStub(RoundStreetNetworkRepository::class);
        $networks->method('acquireWarmLock')->willReturn(true);

        return $networks;
    }

    private function service(
        RoundStreetNetworkRepository $networks,
        ?MockHttpClient $httpClient = null,
        ?RoundRepository $rounds = null,
        ?HidingZoneRepository $zones = null,
    ): StreetNetworkService {
        return new StreetNetworkService(
            $rounds ?? $this->createStub(RoundRepository::class),
            $zones ?? $this->createStub(HidingZoneRepository::class),
            $networks,
            new OverpassHttpClient($httpClient ?? new MockHttpClient(), 'http://mirror/api', false),
            $this->hiderGuard(),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function hiderToken(Game $game): string
    {
        $mercure = new MercureJwtService(self::SECRET);
        $this->subscriber ??= new Player($game, AccountFactory::create('Alice', 'test-password'));

        return $mercure->issueSubscriberToken(
            [$mercure->playerEndgameTopic($this->subscriber->getUuid())],
            $this->subscriber->getUuid(),
        );
    }

    /** The identity comes from the request header now, so each case stages its own token first. */
    private function withSubscriberToken(string $token): void
    {
        $request = new Request();
        $request->headers->set(IdentityResolver::HEADER, $token);
        $stack = new RequestStack();
        $stack->push($request);
        $this->requestStack = $stack;
    }

    private function hiderGuard(): HiderGuard
    {
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturnCallback(
            fn (string $uuid): ?Player => $this->subscriber?->getUuid() === $uuid ? $this->subscriber : null,
        );

        $identity = new IdentityResolver(new MercureJwtService(self::SECRET), $players, $this->requestStack());

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturnCallback(
            fn (Round $round, Player $player): RoundMembership
                => new RoundMembership($round, $player, $this->subscriberSide),
        );

        return new HiderGuard($identity, $memberships);
    }

    private function requestStack(): RequestStack
    {
        return $this->requestStack ?? new RequestStack();
    }

    private static function round(GameSize $size, RoundStatus $status): Round
    {
        $round = new Round(new Game('Berlin', $size, Edition::Metric));
        $round->setStatus($status);

        return $round;
    }

    private static function zone(Round $round, float $longitude, float $radiusMeters): HidingZone
    {
        return new HidingZone($round, new Point($longitude, 52.52), $radiusMeters);
    }

    private static function readyNetwork(Round $round, float $longitude, float $radiusMeters): RoundStreetNetwork
    {
        $network = new RoundStreetNetwork($round, new Point($longitude, 52.52), $radiusMeters);
        $network
            ->setPayload([[
                'class' => 'residential',
                'coordinates' => [[13.4, 52.5], [13.41, 52.51]],
                'junctionIndices' => [],
            ]])
            ->setWayCount(1)
            ->setFetchedAt(new \DateTimeImmutable('2026-08-01 12:00:00'))
            ->setStatus(StreetNetworkStatus::Ready)
            ->setAttempts(1);

        return $network;
    }

    private static function oversizeNetwork(Round $round): RoundStreetNetwork
    {
        return new RoundStreetNetwork(
            $round,
            new Point(self::OVERSIZE_LNG, self::OVERSIZE_LAT),
            self::OVERSIZE_RADIUS,
        );
    }

    /**
     * 620 disjoint vertical ways of 700 nodes each: the geometry alone encodes to ~10.0 MB against the 8 MiB
     * payload cap, while the raw answer stays ~9% under the response cap. No two ways share a longitude, so
     * junctionIndices are all empty and contribute nothing to the size.
     */
    private static function oversizeOverpassResponse(): string
    {
        $elements = [];
        for ($way = 0; $way < 620; ++$way) {
            $longitude = round(self::OVERSIZE_LNG - $way * 0.00002, 5);
            $geometry = [];
            for ($node = 0; $node < 700; ++$node) {
                $geometry[] = ['lat' => round(self::OVERSIZE_LAT - $node * 0.00002, 5), 'lon' => $longitude];
            }
            $elements[] = [
                'type' => 'way',
                'id' => $way,
                'tags' => ['highway' => 'residential'],
                'geometry' => $geometry,
            ];
        }

        return json_encode(['version' => 0.6, 'elements' => $elements], JSON_THROW_ON_ERROR);
    }
}
