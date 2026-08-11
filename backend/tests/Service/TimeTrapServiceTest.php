<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Entity\GameTransitStation;
use App\Entity\Player;
use App\Entity\PlayerLocation;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Entity\TimeTrap;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Enum\TimeTrapStatus;
use App\Exception\FunctionalException;
use App\GeoDistance;
use App\Repository\ChatMessageRepository;
use App\Repository\GameTransitStationRepository;
use App\Repository\PlayerLocationRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Repository\TimeTrapRepository;
use App\Service\ChatService;
use App\Service\MercureJwtService;
use App\Service\RoundClock;
use App\Service\TimeTrapPublisher;
use App\Service\TimeTrapService;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use App\TimeTrapRules;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mercure\Update;

#[CoversClass(TimeTrapService::class)]
final class TimeTrapServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    private const float STATION_LAT = 52.52;

    private const float STATION_LNG = 13.405;

    private Side $side = Side::Hider;

    private FakeMercureHub $hub;

    private ?ChatMessageRepository $messages = null;

    private ?RoundRepository $rounds = null;

    private ?TimeTrapRepository $traps = null;

    private ?GameTransitStationRepository $stations = null;

    private ?ImageStorageInterface $imageStorage = null;

    /** @var list<PlayerLocation> latest ping first */
    private array $pings = [];

    protected function setUp(): void
    {
        $this->hub = new FakeMercureHub();
    }

    #[Test]
    public function itSnapsAPlacementToTheNearestImportedStation(): void
    {
        $round = self::huntedRound();
        $station = self::station($round->getGame());
        $this->stations = $this->stationsFinding($station);
        $traps = $this->trapsMock();
        $traps->expects(self::once())->method('save');
        $this->traps = $traps;

        $trap = $this->service($round)
            ->place($round->getUuid(), 'player-uuid', 52.5211, 13.4062, $this->cardPhoto());

        self::assertSame($station, $trap->getStation());
        self::assertSame('Alexanderplatz', $trap->getStationName());
        self::assertSame(self::STATION_LNG, $trap->getPoint()->getLongitude());
        self::assertSame(self::STATION_LAT, $trap->getPoint()->getLatitude());
        self::assertSame(TimeTrapStatus::Armed, $trap->getStatus());
    }

    #[Test]
    public function itAnnouncesThePlacedTrapWithItsIntervalAndIncrement(): void
    {
        $round = self::huntedRound();
        $this->stations = $this->stationsFinding(self::station($round->getGame()));
        $this->messages = $this->expectOneMessage(
            'trap.placed',
            ['station' => 'Alexanderplatz', 'increment' => 6, 'interval' => 30],
        );

        $this->service($round)
            ->place($round->getUuid(), 'player-uuid', self::STATION_LAT, self::STATION_LNG, $this->cardPhoto());

        $update = $this->onlyTrapUpdate();
        $payload = self::decode($update);
        self::assertSame(
            [new MercureJwtService(self::SECRET)->timeTrapTopic($round->getGame(), $round)],
            $update->getTopics(),
        );
        self::assertSame('placed', $payload['action']);
        self::assertSame('armed', $payload['status']);
        self::assertSame('Alexanderplatz', $payload['stationName']);
        self::assertSame('30', $payload['intervalMinutes']);
        self::assertSame('6', $payload['incrementMinutes']);
        self::assertSame('0', $payload['valueSeconds']);
        self::assertNull($payload['frozenValueSeconds']);
        self::assertNull($payload['detectedByName']);
        self::assertNull($payload['awardedSeconds']);
    }

    #[Test]
    public function itRefusesAPlacementWithNoStationInRange(): void
    {
        $round = self::huntedRound();
        $this->stations = $this->stationsFinding(null);
        $traps = $this->trapsMock();
        $traps->expects(self::never())->method('save');
        $this->traps = $traps;

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('A time trap has to be placed on a transit station.');

        $this->service($round)->place($round->getUuid(), 'player-uuid', 52.6, 13.6, $this->cardPhoto());
    }

    #[Test]
    public function itRefusesAPlacementOnceTheRoundHoldsTheCap(): void
    {
        $round = self::huntedRound();
        $this->stations = $this->stationsFinding(self::station($round->getGame()));
        $traps = $this->trapsMock();
        $traps->method('countByRound')->willReturn(TimeTrapRules::MAX_TRAPS_PER_ROUND);
        $traps->expects(self::never())->method('save');
        $this->traps = $traps;

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('This round already holds the maximum number of time traps.');

        $this->service($round)
            ->place($round->getUuid(), 'player-uuid', self::STATION_LAT, self::STATION_LNG, $this->cardPhoto());
    }

    #[Test]
    public function itRefusesAPlacementWhileTheHidingPeriodIsStillRunning(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Hiding)->setHidingPeriodEndsAt(new \DateTimeImmutable('+10 minutes'));
        $this->stations = $this->stationsFinding(self::station($game));

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Time traps only come into play once the seekers are hunting.');

        $this->service($round)
            ->place($round->getUuid(), 'player-uuid', self::STATION_LAT, self::STATION_LNG, $this->cardPhoto());
    }

    #[Test]
    public function itRefusesASeekerPlacingATrap(): void
    {
        $round = self::huntedRound();
        $this->side = Side::Seeker;
        $this->stations = $this->stationsFinding(self::station($round->getGame()));

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Only a hider may place a time trap.');

        $this->service($round)
            ->place($round->getUuid(), 'player-uuid', self::STATION_LAT, self::STATION_LNG, $this->cardPhoto());
    }

    /**
     * A train at 120 km/h covers over 300 m between pings, so the two fixes straddle the station
     * with neither one inside the trip radius. Only the whole segment can catch that pass.
     */
    #[Test]
    public function itTestsTheSegmentBetweenTheTwoLatestPingsSoAFastFlyByIsNotMissed(): void
    {
        $round = self::huntedRound();
        $seeker = new Player($round->getGame(), AccountFactory::create('Bob', 'test-password'));
        $station = new Point(self::STATION_LNG, self::STATION_LAT);
        $before = new Point(13.3980, self::STATION_LAT);
        $after = new Point(13.4120, self::STATION_LAT);

        self::assertGreaterThan(TimeTrapRules::TRIP_RADIUS_METERS, GeoDistance::metersBetween($before, $station));
        self::assertGreaterThan(TimeTrapRules::TRIP_RADIUS_METERS, GeoDistance::metersBetween($after, $station));

        $this->pings = [
            self::pingAt($round, $seeker, $after, 'now'),
            self::pingAt($round, $seeker, $before, '-5 seconds'),
        ];
        $traps = $this->trapsMock();
        $traps->expects(self::once())->method('findTrippedUuids')
            ->with(
                $round,
                $before,
                $after,
                TimeTrapRules::TRIP_RADIUS_METERS,
                self::callback(static function (\DateTimeImmutable $cooldownBefore): bool {
                    $expected = new \DateTimeImmutable(
                        sprintf('-%d minutes', TimeTrapRules::REDETECT_COOLDOWN_MINUTES),
                    );
                    self::assertLessThanOrEqual($expected, $cooldownBefore);

                    return true;
                }),
            )
            ->willReturn([]);
        $this->traps = $traps;

        $this->service($round)->checkTrip($round, $seeker, $after);
    }

    /**
     * A seeker who rides underground and resurfaces kilometres away leaves a chord that never described a
     * journey. The segment is only justified by the ping cadence, so past the window it is dropped.
     */
    #[Test]
    public function itIgnoresTheSegmentWhenTheTwoPingsAreTooFarApartInTime(): void
    {
        $round = self::huntedRound();
        $seeker = new Player($round->getGame(), AccountFactory::create('Bob', 'test-password'));
        $resurfaced = new Point(13.4120, self::STATION_LAT);
        $submerged = new Point(13.3080, self::STATION_LAT);

        $this->pings = [
            self::pingAt($round, $seeker, $resurfaced, 'now'),
            self::pingAt($round, $seeker, $submerged, '-15 minutes'),
        ];
        $traps = $this->trapsMock();
        $traps->expects(self::once())->method('findTrippedUuids')
            ->with($round, $resurfaced, $resurfaced)
            ->willReturn([]);
        $this->traps = $traps;

        $this->service($round)->checkTrip($round, $seeker, $resurfaced);
    }

    #[Test]
    public function itIgnoresTheSegmentWhenBothPingsShareATimestamp(): void
    {
        $round = self::huntedRound();
        $seeker = new Player($round->getGame(), AccountFactory::create('Bob', 'test-password'));
        $here = new Point(13.4120, self::STATION_LAT);
        $there = new Point(13.3080, self::STATION_LAT);

        $this->pings = [
            self::pingAt($round, $seeker, $here, '2026-07-31 12:00:00'),
            self::pingAt($round, $seeker, $there, '2026-07-31 12:00:00'),
        ];
        $traps = $this->trapsMock();
        $traps->expects(self::once())->method('findTrippedUuids')
            ->with($round, $here, $here)
            ->willReturn([]);
        $this->traps = $traps;

        $this->service($round)->checkTrip($round, $seeker, $here);
    }

    #[Test]
    public function itFallsBackToAPointTestWhenTheSeekerHasNoEarlierPing(): void
    {
        $round = self::huntedRound();
        $seeker = new Player($round->getGame(), AccountFactory::create('Bob', 'test-password'));
        $point = new Point(self::STATION_LNG, self::STATION_LAT);

        $traps = $this->trapsMock();
        $traps->expects(self::once())->method('findTrippedUuids')
            ->with($round, $point, $point)
            ->willReturn([]);
        $this->traps = $traps;

        $this->service($round)->checkTrip($round, $seeker, $point);
    }

    #[Test]
    public function itFreezesTheValueAtTheMomentOfThePassAndPostsTheEvidence(): void
    {
        $round = self::huntedRound();
        $seeker = new Player($round->getGame(), AccountFactory::create('Bob', 'test-password'));
        $trap = self::trap($round);
        $point = new Point(self::STATION_LNG, self::STATION_LAT);

        $traps = $this->trapsMock();
        $traps->method('findTrippedUuids')->willReturn([$trap->getUuid()]);
        $traps->method('findOneByUuid')->willReturn($trap);
        $traps->expects(self::once())->method('save')->with($trap);
        $this->traps = $traps;
        $this->messages = $this->expectOneMessage(
            'trap.detected',
            ['station' => 'Alexanderplatz', 'seeker' => 'Bob', 'minutes' => 0, 'speed' => 0],
        );

        $this->service($round)->checkTrip($round, $seeker, $point);

        self::assertSame(TimeTrapStatus::Pending, $trap->getStatus());
        self::assertSame(0, $trap->getFrozenValueSeconds());
        self::assertSame($seeker, $trap->getDetectedByPlayer());
        self::assertNotNull($trap->getDetectedAt());

        $payload = self::decode($this->onlyTrapUpdate());
        self::assertSame('detected', $payload['action']);
        self::assertSame('pending', $payload['status']);
        self::assertSame('Bob', $payload['detectedByName']);
        self::assertSame('0', $payload['frozenValueSeconds']);
    }

    #[Test]
    public function itStaysQuietWhileAMoveWindowIsOpen(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Hiding)
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('+20 minutes'))
            ->setInMovePeriod(true);

        $traps = $this->trapsMock();
        $traps->expects(self::never())->method('findTrippedUuids');
        $this->traps = $traps;

        $this->service($round)->checkTrip(
            $round,
            new Player($game, AccountFactory::create('Bob', 'test-password')),
            new Point(self::STATION_LNG, self::STATION_LAT),
        );
    }

    #[Test]
    public function itStaysQuietForASeekerWhoLeftTheGame(): void
    {
        $round = self::huntedRound();
        $seeker = new Player($round->getGame(), AccountFactory::create('Bob', 'test-password'));
        $seeker->markLeft(new \DateTimeImmutable());

        $traps = $this->trapsMock();
        $traps->expects(self::never())->method('findTrippedUuids');
        $this->traps = $traps;

        $this->service($round)->checkTrip($round, $seeker, new Point(self::STATION_LNG, self::STATION_LAT));
    }

    #[Test]
    public function itCreditsTheFrozenValueToTheRoundWhenTheSeekersConfirm(): void
    {
        $round = self::huntedRound();
        $trap = self::trap($round);
        $trap->setStatus(TimeTrapStatus::Pending)->setFrozenValueSeconds(360);

        $this->side = Side::Seeker;
        $this->traps = $this->trapsFinding($trap);
        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        // Credited by a database-side increment, so two traps confirmed at once cannot lose each other.
        $rounds->expects(self::once())->method('creditTrapBonusSeconds')->with($round, 360);
        $rounds->expects(self::never())->method('save');
        $this->rounds = $rounds;
        $this->messages = $this->expectOneMessage(
            'trap.sprung',
            ['station' => 'Alexanderplatz', 'minutes' => 6],
        );

        $resolved = $this->service($round)->resolve($trap->getUuid(), 'player-uuid', confirmed: true);

        self::assertSame(TimeTrapStatus::Sprung, $resolved->getStatus());
        self::assertSame(360, $resolved->getAwardedSeconds());
        self::assertSame('sprung', self::decode($this->onlyTrapUpdate())['action']);
    }

    /** Two seekers pinging in the same second must yield one notice, not one per device. */
    #[Test]
    public function itPostsNothingWhenAnotherSeekerAlreadyClaimedTheDetection(): void
    {
        $round = self::huntedRound();
        $seeker = new Player($round->getGame(), AccountFactory::create('Bob', 'test-password'));
        $trap = self::trap($round);

        $this->traps = $this->trapsLosingTheClaim($trap);
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');
        $this->messages = $messages;

        $this->service($round)->checkTrip($round, $seeker, new Point(self::STATION_LNG, self::STATION_LAT));

        self::assertSame(TimeTrapStatus::Armed, $trap->getStatus());
        self::assertNull($trap->getDetectedAt());
        self::assertSame([], $this->hub->published());
    }

    /**
     * A simultaneous Confirm and Dismiss would otherwise bank the value AND re-arm the same trap, letting
     * it pay out twice. Only the caller that moves the status through wins.
     */
    #[Test]
    public function itRefusesAResolutionThatLostTheRaceToTheOtherAnswer(): void
    {
        $round = self::huntedRound();
        $trap = self::trap($round);
        $trap->setStatus(TimeTrapStatus::Pending)->setFrozenValueSeconds(360);

        $this->side = Side::Seeker;
        $this->traps = $this->trapsLosingTheClaim($trap);
        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $rounds->expects(self::never())->method('creditTrapBonusSeconds');
        $this->rounds = $rounds;

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('This time trap is not awaiting a resolution.');

        $this->service($round)->resolve($trap->getUuid(), 'player-uuid', confirmed: true);
    }

    #[Test]
    public function itRefusesADismissalThatLostTheRaceToTheOtherAnswer(): void
    {
        $round = self::huntedRound();
        $trap = self::trap($round);
        $trap->setStatus(TimeTrapStatus::Pending)->setFrozenValueSeconds(360);

        $this->side = Side::Seeker;
        $this->traps = $this->trapsLosingTheClaim($trap);

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('This time trap is not awaiting a resolution.');

        $this->service($round)->resolve($trap->getUuid(), 'player-uuid', confirmed: false);
    }

    /** The round-end cutoff binds a pending detection too: the score was already announced and ranked. */
    #[Test]
    public function itRefusesToResolveADetectionOnceTheRoundHasEnded(): void
    {
        $round = self::huntedRound();
        $trap = self::trap($round);
        $trap->setStatus(TimeTrapStatus::Pending)->setFrozenValueSeconds(360);
        $round->setStatus(RoundStatus::Ended)->setSeekingEndedAt(new \DateTimeImmutable());

        $this->side = Side::Seeker;
        $this->traps = $this->trapsFinding($trap);
        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $rounds->expects(self::never())->method('creditTrapBonusSeconds');
        $this->rounds = $rounds;

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Time traps can only be resolved while the seekers are hunting.');

        $this->service($round)->resolve($trap->getUuid(), 'player-uuid', confirmed: true);
    }

    /**
     * The photo is the play's social proof, so a storage failure must not leave an armed, accruing trap
     * that was never announced and has already eaten a cap slot.
     */
    #[Test]
    public function itCommitsNoTrapWhenTheCardPhotoCannotBeStored(): void
    {
        $round = self::huntedRound();
        $this->stations = $this->stationsFinding(self::station($round->getGame()));
        $traps = $this->trapsMock();
        $traps->expects(self::never())->method('save');
        $this->traps = $traps;
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');
        $this->messages = $messages;

        $storage = $this->createStub(ImageStorageInterface::class);
        $storage->method('store')->willThrowException(new \RuntimeException('disk full'));
        $this->imageStorage = $storage;

        $this->expectException(\RuntimeException::class);

        $this->service($round)
            ->place($round->getUuid(), 'player-uuid', self::STATION_LAT, self::STATION_LNG, $this->cardPhoto());
    }

    #[Test]
    public function itLeavesTheScoreAloneAndReArmsTheTrapWhenTheSeekersDismiss(): void
    {
        $round = self::huntedRound();
        $trap = self::trap($round);
        $trap->setStatus(TimeTrapStatus::Pending)
            ->setFrozenValueSeconds(360)
            ->setDetectedAt(new \DateTimeImmutable())
            ->setDetectedByPlayer(new Player($round->getGame(), AccountFactory::create('Bob', 'test-password')));

        $this->side = Side::Seeker;
        $this->traps = $this->trapsFinding($trap);
        $this->messages = $this->expectOneMessage('trap.dismissed', ['station' => 'Alexanderplatz']);

        $resolved = $this->service($round)->resolve($trap->getUuid(), 'player-uuid', confirmed: false);

        self::assertSame(TimeTrapStatus::Armed, $resolved->getStatus());
        self::assertSame(0, $round->getTrapBonusSeconds());
        self::assertNull($resolved->getAwardedSeconds());
        self::assertNull($resolved->getFrozenValueSeconds());
        self::assertNull($resolved->getDetectedAt());
        self::assertNull($resolved->getDetectedByPlayer());
        self::assertNotNull($resolved->getRearmedAt());
        self::assertSame('dismissed', self::decode($this->onlyTrapUpdate())['action']);
    }

    #[Test]
    public function itRefusesAResolutionOfATrapThatIsNotAwaitingOne(): void
    {
        $round = self::huntedRound();
        $trap = self::trap($round);
        $this->side = Side::Seeker;
        $this->traps = $this->trapsFinding($trap);

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('This time trap is not awaiting a resolution.');

        $this->service($round)->resolve($trap->getUuid(), 'player-uuid', confirmed: true);
    }

    #[Test]
    public function itRefusesAHiderResolvingADetection(): void
    {
        $round = self::huntedRound();
        $trap = self::trap($round);
        $trap->setStatus(TimeTrapStatus::Pending)->setFrozenValueSeconds(360);
        $this->traps = $this->trapsFinding($trap);

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Only a seeker may resolve a time trap.');

        $this->service($round)->resolve($trap->getUuid(), 'player-uuid', confirmed: true);
    }

    private static function huntedRound(): Round
    {
        $round = new Round(new Game('Berlin', GameSize::Medium, Edition::Metric));
        $round->setStatus(RoundStatus::Seeking);

        return $round;
    }

    private static function station(Game $game): GameTransitStation
    {
        return new GameTransitStation(
            $game,
            'de:11000:900100003',
            'Alexanderplatz',
            new Point(self::STATION_LNG, self::STATION_LAT),
            ['U2'],
        );
    }

    private static function trap(Round $round): TimeTrap
    {
        return new TimeTrap($round, new Player($round->getGame(), AccountFactory::create('Alice', 'test-password')), self::station($round->getGame()));
    }

    private function stationsFinding(?GameTransitStation $station): GameTransitStationRepository
    {
        $stations = $this->createStub(GameTransitStationRepository::class);
        $stations->method('findNearestWithin')->willReturn($station);

        return $stations;
    }

    private function trapsFinding(TimeTrap $trap): TimeTrapRepository
    {
        $traps = $this->createStub(TimeTrapRepository::class);
        $traps->method('findOneByUuid')->willReturn($trap);
        $traps->method('claimStatus')->willReturn(true);

        return $traps;
    }

    /** The status claim wins unless a test is specifically about losing the race. */
    private function trapsMock(): TimeTrapRepository&MockObject
    {
        $traps = $this->createMock(TimeTrapRepository::class);
        $traps->method('claimStatus')->willReturn(true);

        return $traps;
    }

    private function trapsLosingTheClaim(TimeTrap $trap): TimeTrapRepository&MockObject
    {
        $traps = $this->createMock(TimeTrapRepository::class);
        $traps->method('findOneByUuid')->willReturn($trap);
        $traps->method('findTrippedUuids')->willReturn([$trap->getUuid()]);
        $traps->method('claimStatus')->willReturn(false);
        $traps->expects(self::never())->method('save');

        return $traps;
    }

    /** PlayerLocation stamps its own recordedAt, and the segment window turns on the gap between two. */
    private static function pingAt(Round $round, Player $player, Point $point, string $at): PlayerLocation
    {
        $location = new PlayerLocation($round, $player, $point);
        new \ReflectionProperty(PlayerLocation::class, 'recordedAt')
            ->setValue($location, new \DateTimeImmutable($at));

        return $location;
    }

    /**
     * @param array<string, string|int> $bodyArgs
     */
    private function expectOneMessage(string $bodyKey, array $bodyArgs): ChatMessageRepository
    {
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            static function (ChatMessage $message) use ($bodyKey, $bodyArgs): bool {
                self::assertSame($bodyKey, $message->getBodyKey());
                self::assertSame($bodyArgs, $message->getBodyArgs());

                return true;
            },
        ));

        return $messages;
    }

    private function cardPhoto(): UploadedFile
    {
        return $this->createStub(UploadedFile::class);
    }

    private function service(Round $round): TimeTrapService
    {
        $player = new Player($round->getGame(), AccountFactory::create('Alice', 'test-password'));

        $rounds = $this->rounds;
        if ($rounds === null) {
            $stub = $this->createStub(RoundRepository::class);
            $stub->method('findOneByUuid')->willReturn($round);
            $rounds = $stub;
        }

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn($player);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')
            ->willReturn(new RoundMembership($round, $player, $this->side));

        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')->willReturn($this->pings[0] ?? null);
        $locations->method('findPreviousByRoundAndPlayer')->willReturn($this->pings[1] ?? null);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $work): mixed => $work());

        return new TimeTrapService(
            $rounds,
            $players,
            $memberships,
            $this->traps ?? $this->trapsFinding(self::trap($round)),
            $this->stations ?? $this->createStub(GameTransitStationRepository::class),
            $locations,
            new ChatService(
                $this->messages ?? $this->createStub(ChatMessageRepository::class),
                new MercureJwtService(self::SECRET),
                $this->hub,
                $this->createStub(ImageStorageInterface::class),
            ),
            $this->imageStorage ?? $this->createStub(ImageStorageInterface::class),
            new RoundClock(),
            new TimeTrapPublisher(new MercureJwtService(self::SECRET), $this->hub),
            $entityManager,
        );
    }

    private function onlyTrapUpdate(): Update
    {
        $updates = array_values(array_filter(
            $this->hub->published(),
            static fn (Update $update): bool => self::decode($update)['type'] === 'time-trap',
        ));
        self::assertCount(1, $updates);

        return $updates[0];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(Update $update): array
    {
        $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
