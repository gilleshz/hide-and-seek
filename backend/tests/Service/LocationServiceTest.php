<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Exception\FunctionalException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameTransitStationRepository;
use App\Repository\PlayerLocationRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Repository\TimeTrapRepository;
use App\Service\ChatService;
use App\Service\EndgameService;
use App\Service\LocationService;
use App\Service\MercureJwtService;
use App\Service\RoundClock;
use App\Service\TimeTrapPublisher;
use App\Service\TimeTrapService;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(LocationService::class)]
final class LocationServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function itPublishesToTheHiderTopicAsPrivateWhenTheSideIsHider(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $player, Side::Hider);

        $locations = $this->createMock(PlayerLocationRepository::class);
        $locations->expects(self::once())->method('save');

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->with(self::callback(
            function (Update $update) use ($game, $round): bool {
                self::assertSame(
                    ["game/{$game->getUuid()}/round/{$round->getUuid()}/hider-locations"],
                    $update->getTopics(),
                );
                self::assertTrue($update->isPrivate());

                return true;
            },
        ));

        $endgameService = $this->createStub(EndgameService::class);

        $service = new LocationService(
            $locations,
            $memberships,
            new MercureJwtService(self::SECRET),
            $hub,
            $endgameService,
            new RoundClock(),
            $this->timeTraps(),
        );
        $result = $service->record($round, $player, new Point(13.4, 52.5));

        self::assertSame($player, $result->location->getPlayer());
        self::assertFalse($result->endgameTriggered);
    }

    #[Test]
    public function itPersistsTheReportedAltitudeOnTheLocation(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $player, Side::Hider);

        $locations = $this->createMock(PlayerLocationRepository::class);
        $locations->expects(self::once())->method('save');

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);

        $service = new LocationService(
            $locations,
            $memberships,
            new MercureJwtService(self::SECRET),
            $this->createStub(HubInterface::class),
            $this->createStub(EndgameService::class),
            new RoundClock(),
            $this->timeTraps(),
        );

        $result = $service->record($round, $player, new Point(13.4, 52.5), -15.0);

        self::assertSame(-15.0, $result->location->getAltitude());
    }

    #[Test]
    public function itPublishesToTheSeekerTopicWhenTheSideIsSeeker(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $membership = new RoundMembership($round, $player, Side::Seeker);

        $locations = $this->createStub(PlayerLocationRepository::class);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->with(self::callback(
            function (Update $update) use ($game, $round): bool {
                self::assertSame(
                    ["game/{$game->getUuid()}/round/{$round->getUuid()}/seeker-locations"],
                    $update->getTopics(),
                );

                return true;
            },
        ));

        $endgameService = $this->createStub(EndgameService::class);
        $endgameService->method('check')->willReturn(null);

        new LocationService(
            $locations,
            $memberships,
            new MercureJwtService(self::SECRET),
            $hub,
            $endgameService,
            new RoundClock(),
            $this->timeTraps(),
        )->record($round, $player, new Point(13.4, 52.5));
    }

    #[Test]
    public function itStartsTheEndgameWhenSeekerEntersZoneAndFlagsTheTriggeringPing(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $seekerMembership = new RoundMembership($round, $seeker, Side::Seeker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($seekerMembership);

        $endgameService = $this->createMock(EndgameService::class);
        $endgameService->method('check')->willReturn($seeker);
        $endgameService->expects(self::once())->method('start')->with($round);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->willReturnCallback(
            function (Update $update) use ($game, $round): string {
                self::assertSame(
                    ["game/{$game->getUuid()}/round/{$round->getUuid()}/seeker-locations"],
                    $update->getTopics(),
                );

                return 'id-1';
            },
        );

        $result = (new LocationService(
            $this->createStub(PlayerLocationRepository::class),
            $memberships,
            new MercureJwtService(self::SECRET),
            $hub,
            $endgameService,
            new RoundClock(),
            $this->timeTraps(),
        ))->record($round, $seeker, new Point(13.4, 52.5));

        self::assertTrue($result->endgameTriggered);
    }

    #[Test]
    public function itDoesNotTriggerEndgameTwice(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setEndgameStartedAt(new \DateTimeImmutable());
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $seekerMembership = new RoundMembership($round, $seeker, Side::Seeker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($seekerMembership);

        $endgameService = $this->createMock(EndgameService::class);
        $endgameService->expects(self::never())->method('check');
        $endgameService->expects(self::never())->method('start');

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish'); // only the location publish

        $result = (new LocationService(
            $this->createStub(PlayerLocationRepository::class),
            $memberships,
            new MercureJwtService(self::SECRET),
            $hub,
            $endgameService,
            new RoundClock(),
            $this->timeTraps(),
        ))->record($round, $seeker, new Point(13.4, 52.5));

        self::assertFalse($result->endgameTriggered);
    }

    #[Test]
    public function itRejectsAPingForAnEndedRound(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Ended);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $player, Side::Hider);

        $locations = $this->createMock(PlayerLocationRepository::class);
        $locations->expects(self::never())->method('save');

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Locations cannot be recorded for an ended round.');

        new LocationService(
            $locations,
            $memberships,
            new MercureJwtService(self::SECRET),
            $hub,
            $this->createStub(EndgameService::class),
            new RoundClock(),
            $this->timeTraps(),
        )->record($round, $player, new Point(13.4, 52.5));
    }

    #[Test]
    public function itRejectsAPlayerWithNoMembershipForTheRound(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(null);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $this->expectException(FunctionalException::class);

        new LocationService(
            $this->createStub(PlayerLocationRepository::class),
            $memberships,
            new MercureJwtService(self::SECRET),
            $hub,
            $this->createStub(EndgameService::class),
            new RoundClock(),
            $this->timeTraps(),
        )->record($round, $player, new Point(13.4, 52.5));
    }

    #[Test]
    public function itRejectsAPlayerFromAnotherGame(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $otherGame = new Game('Paris', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $player = new Player($otherGame, AccountFactory::create('Eve', 'test-password'));

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $this->expectException(FunctionalException::class);

        new LocationService(
            $this->createStub(PlayerLocationRepository::class),
            $this->createStub(RoundMembershipRepository::class),
            new MercureJwtService(self::SECRET),
            $hub,
            $this->createStub(EndgameService::class),
            new RoundClock(),
            $this->timeTraps(),
        )->record($round, $player, new Point(13.4, 52.5));
    }

    /** TimeTrapService is final, so the trip hook gets the real thing over stubbed collaborators. */
    private function timeTraps(): TimeTrapService
    {
        $traps = $this->createStub(TimeTrapRepository::class);
        $traps->method('findTrippedUuids')->willReturn([]);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $work): mixed => $work());

        return new TimeTrapService(
            $this->createStub(RoundRepository::class),
            $this->createStub(PlayerRepository::class),
            $this->createStub(RoundMembershipRepository::class),
            $traps,
            $this->createStub(GameTransitStationRepository::class),
            $this->createStub(PlayerLocationRepository::class),
            new ChatService(
                $this->createStub(ChatMessageRepository::class),
                new MercureJwtService(self::SECRET),
                new FakeMercureHub(),
                $this->createStub(ImageStorageInterface::class),
            ),
            $this->createStub(ImageStorageInterface::class),
            new RoundClock(),
            new TimeTrapPublisher(new MercureJwtService(self::SECRET), new FakeMercureHub()),
            $entityManager,
        );
    }
}
