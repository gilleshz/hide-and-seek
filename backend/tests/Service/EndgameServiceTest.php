<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\HidingZone;
use App\Entity\Player;
use App\Entity\PlayerLocation;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\Side;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerLocationRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\EndgameService;
use App\Service\MercureJwtService;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(EndgameService::class)]
final class EndgameServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function itReturnsNullWhenNoZoneExists(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);

        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn(null);

        $service = $this->checkService(
            $this->createStub(RoundMembershipRepository::class),
            $this->createStub(PlayerLocationRepository::class),
            $zones,
        );

        self::assertNull($service->check($round));
    }

    #[Test]
    public function itReturnsNullWhenNoSeekerIsWithinZoneRadius(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $stationPoint = new Point(13.405, 52.52);
        $seekerPoint = new Point(13.41, 52.53); // ~1.2km away, zone radius 500m

        $zone = new HidingZone($round, $stationPoint, 500.0);

        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn($zone);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findByRound')
            ->willReturn([new RoundMembership($round, $seeker, Side::Seeker)]);

        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')
            ->willReturn(new PlayerLocation($round, $seeker, $seekerPoint));

        $service = $this->checkService($memberships, $locations, $zones);

        self::assertNull($service->check($round));
    }

    #[Test]
    public function itReturnsSeekerWhenWithinZoneRadius(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $stationPoint = new Point(13.405, 52.52);
        $seekerPoint = new Point(13.40501, 52.52001); // ~1.5m away, zone radius 500m

        $zone = new HidingZone($round, $stationPoint, 500.0);

        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn($zone);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findByRound')
            ->willReturn([new RoundMembership($round, $seeker, Side::Seeker)]);

        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')
            ->willReturn(new PlayerLocation($round, $seeker, $seekerPoint));

        $service = $this->checkService($memberships, $locations, $zones);

        $result = $service->check($round);

        self::assertNotNull($result);
        self::assertSame($seeker->getUuid(), $result->getUuid());
    }

    #[Test]
    public function itRespectsExpandedZoneRadius(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $stationPoint = new Point(13.405, 52.52);
        $seekerPoint = new Point(13.405, 52.521); // ~111m away

        $zone = new HidingZone($round, $stationPoint, 1500.0); // expanded radius

        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn($zone);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findByRound')
            ->willReturn([new RoundMembership($round, $seeker, Side::Seeker)]);

        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')
            ->willReturn(new PlayerLocation($round, $seeker, $seekerPoint));

        $service = $this->checkService($memberships, $locations, $zones);

        $result = $service->check($round);

        self::assertNotNull($result);
        self::assertSame($seeker->getUuid(), $result->getUuid());
    }

    #[Test]
    public function itIgnoresASeekerWhoHasLeft(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $left = new Player($game, AccountFactory::create('Bob', 'test-password'))->markLeft(new \DateTimeImmutable());

        $stationPoint = new Point(13.405, 52.52);
        $zone = new HidingZone($round, $stationPoint, 500.0);

        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn($zone);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findByRound')
            ->willReturn([new RoundMembership($round, $left, Side::Seeker)]);

        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')
            ->willReturn(new PlayerLocation($round, $left, $stationPoint));

        $service = $this->checkService($memberships, $locations, $zones);

        self::assertNull($service->check($round));
    }

    #[Test]
    public function itIgnoresHiderLocations(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $stationPoint = new Point(13.405, 52.52);
        $hiderPoint = new Point(13.405, 52.52); // at the station, but ignored

        $zone = new HidingZone($round, $stationPoint, 500.0);

        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn($zone);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findByRound')
            ->willReturn([new RoundMembership($round, $hider, Side::Hider)]);

        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')
            ->willReturn(new PlayerLocation($round, $hider, $hiderPoint));

        $service = $this->checkService($memberships, $locations, $zones);

        self::assertNull($service->check($round));
    }

    #[Test]
    public function startingTheEndgameNotifiesEveryHiderOnTheTeam(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $first = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $second = new Player($game, AccountFactory::create('Ada', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findHidersByRound')->willReturn([
            new RoundMembership($round, $first, Side::Hider),
            new RoundMembership($round, $second, Side::Hider),
        ]);

        $hub = new FakeMercureHub();
        $this->startService($memberships, $hub)->start($round);

        $topics = array_map(
            static fn (Update $update): array => $update->getTopics(),
            $hub->published(),
        );
        self::assertSame(
            [["player/{$first->getUuid()}/endgame"], ["player/{$second->getUuid()}/endgame"]],
            $topics,
        );
        self::assertNotNull($round->getEndgameStartedAt());
    }

    #[Test]
    public function startingAnEndgameThatAlreadyStartedNotifiesNobodyAgain(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $round->setEndgameStartedAt(new \DateTimeImmutable('-1 minute'));

        $memberships = $this->createMock(RoundMembershipRepository::class);
        $memberships->expects(self::never())->method('findHidersByRound');

        $hub = new FakeMercureHub();
        $this->startService($memberships, $hub)->start($round);

        self::assertSame([], $hub->published());
    }

    #[Test]
    public function theEndgameNotificationCarriesTheFlagAndIsPrivate(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findHidersByRound')->willReturn([
            new RoundMembership($round, $hider, Side::Hider),
        ]);

        $hub = new FakeMercureHub();
        $this->startService($memberships, $hub)->start($round);

        $updates = $hub->published();
        self::assertCount(1, $updates);
        self::assertTrue($updates[0]->isPrivate());
        self::assertSame(
            ['type' => 'endgame', 'endgame' => true],
            json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    private function startService(RoundMembershipRepository $memberships, HubInterface $hub): EndgameService
    {
        return new EndgameService(
            $memberships,
            $this->createStub(PlayerLocationRepository::class),
            $this->createStub(HidingZoneRepository::class),
            $this->createStub(RoundRepository::class),
            new MercureJwtService(self::SECRET),
            $hub,
        );
    }

    private function checkService(
        RoundMembershipRepository $memberships,
        PlayerLocationRepository $locations,
        HidingZoneRepository $zones,
    ): EndgameService {
        return new EndgameService(
            $memberships,
            $locations,
            $zones,
            $this->createStub(RoundRepository::class),
            new MercureJwtService(self::SECRET),
            new FakeMercureHub(),
        );
    }
}
