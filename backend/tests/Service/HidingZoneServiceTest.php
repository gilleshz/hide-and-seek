<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ZonePlacement;
use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Entity\HidingZone;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\ChatMessageType;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Enum\ZoneCard;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\ChatMessageRepository;
use App\Repository\FeatureRepository;
use App\Repository\GameRepository;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Repository\RoundStreetNetworkRepository;
use App\Service\ChatService;
use App\Service\HiderGuard;
use App\Service\HidingZonePublisher;
use App\Service\HidingZoneService;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Service\OverpassHttpClient;
use App\Service\PossibleAreaService;
use App\Service\QuestionMessageFormatter;
use App\Service\RoundClock;
use App\Service\RoundService;
use App\Service\StreetNetworkService;
use App\Service\ZoneCardMessageBuilder;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(HidingZoneService::class)]
final class HidingZoneServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    private function service(
        RoundRepository $r,
        PlayerRepository $p,
        RoundMembershipRepository $m,
        HidingZoneRepository $z,
        ?HubInterface $hub = null,
        ?ChatMessageRepository $messages = null,
    ): HidingZoneService {
        $resolvedHub = $hub ?? new FakeMercureHub();
        $chat = new ChatService(
            $messages ?? $this->createStub(ChatMessageRepository::class),
            new MercureJwtService(self::SECRET),
            $resolvedHub,
            $this->createStub(ImageStorageInterface::class),
        );

        $hiderGuard = new HiderGuard($this->identityResolver($p), $m);
        $streetNetworks = new StreetNetworkService(
            $r,
            $z,
            $this->createStub(RoundStreetNetworkRepository::class),
            new OverpassHttpClient(new MockHttpClient(), 'http://mirror/api', false),
            $hiderGuard,
            $this->createStub(LoggerInterface::class),
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $work): mixed => $work());

        return new HidingZoneService(
            $r,
            $p,
            $m,
            $z,
            $hiderGuard,
            $chat,
            $this->createStub(ImageStorageInterface::class),
            $this->createStub(PossibleAreaService::class),
            // RoundService is final, so the move path gets the real thing over stubbed collaborators.
            new RoundService(
                $r,
                $this->createStub(GameRepository::class),
                $m,
                $p,
                $z,
                $chat,
                new MercureJwtService(self::SECRET),
                $resolvedHub,
                $this->createStub(LoggerInterface::class),
            ),
            new RoundClock(),
            new HidingZonePublisher(new MercureJwtService(self::SECRET), $resolvedHub, $streetNetworks),
            new ZoneCardMessageBuilder(new QuestionMessageFormatter($this->createStub(FeatureRepository::class))),
            $entityManager,
        );
    }

    /** The zone-read gate resolves identity from the request header; no test exercises that path. */
    private function identityResolver(PlayerRepository $players): IdentityResolver
    {
        $stack = new RequestStack();
        $stack->push(new Request());

        return new IdentityResolver(new MercureJwtService(self::SECRET), $players, $stack);
    }

    #[Test]
    public function itCreatesAZoneForAHiderWithTheDefaultRadius(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $player, Side::Hider);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn($player);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);
        $zones = $this->createMock(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn(null);
        $zones->expects(self::once())->method('save');

        $zone = $this->service($rounds, $players, $memberships, $zones)
            ->setZone($round->getUuid(), $player->getUuid(), new ZonePlacement(new Point(13.405, 52.52), null));

        self::assertSame(500.0, $zone->getRadiusMeters());
        self::assertSame(13.405, $zone->getStationPoint()->getLongitude());
    }

    #[Test]
    public function itUsesTheExplicitRadiusWhenProvided(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $player, Side::Hider);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn($player);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);
        $zones = $this->createMock(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn(null);
        $zones->expects(self::once())->method('save');

        $zone = $this->service($rounds, $players, $memberships, $zones)
            ->setZone($round->getUuid(), $player->getUuid(), new ZonePlacement(new Point(13.405, 52.52), 250.0));

        self::assertSame(250.0, $zone->getRadiusMeters());
    }

    #[Test]
    public function itUpdatesAnExistingZoneInsteadOfCreatingANewOne(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $player, Side::Hider);
        $existing = new HidingZone($round, new Point(0.0, 0.0), 500.0);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn($player);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);
        $zones = $this->createMock(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn($existing);
        $zones->expects(self::once())->method('save')->with($existing);

        $zone = $this->service($rounds, $players, $memberships, $zones)
            ->setZone($round->getUuid(), $player->getUuid(), new ZonePlacement(new Point(13.405, 52.52), 750.0));

        self::assertSame($existing, $zone);
        self::assertSame(750.0, $zone->getRadiusMeters());
        self::assertSame(13.405, $zone->getStationPoint()->getLongitude());
    }

    #[Test]
    public function itRejectsASeekerSettingTheZone(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $membership = new RoundMembership($round, $player, Side::Seeker);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn($player);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);
        $zones = $this->createMock(HidingZoneRepository::class);
        $zones->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);

        $this->service($rounds, $players, $memberships, $zones)
            ->setZone($round->getUuid(), $player->getUuid(), new ZonePlacement(new Point(13.405, 52.52), null));
    }

    #[Test]
    public function itRejectsAPlayerWithNoMembershipForTheRound(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn($player);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(null);
        $zones = $this->createMock(HidingZoneRepository::class);
        $zones->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);

        $this->service($rounds, $players, $memberships, $zones)
            ->setZone($round->getUuid(), $player->getUuid(), new ZonePlacement(new Point(13.405, 52.52), null));
    }

    #[Test]
    public function itRejectsAnUnknownRound(): void
    {
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->service(
            $rounds,
            $this->createStub(PlayerRepository::class),
            $this->createStub(RoundMembershipRepository::class),
            $this->createStub(HidingZoneRepository::class),
        )->setZone('00000000-0000-0000-0000-000000000000', 'irrelevant', new ZonePlacement(new Point(0.0, 0.0), null));
    }

    #[Test]
    public function itRejectsAnUnknownPlayer(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->service(
            $rounds,
            $players,
            $this->createStub(RoundMembershipRepository::class),
            $this->createStub(HidingZoneRepository::class),
        )->setZone($round->getUuid(), 'irrelevant', new ZonePlacement(new Point(0.0, 0.0), null));
    }

    #[Test]
    public function itPublishesTheStationPointOnlyOnTheHiderOnlyTopic(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hub = new FakeMercureHub();
        $mercure = new MercureJwtService(self::SECRET);

        $this->hiderService($round, $hub)
            ->setZone($round->getUuid(), 'player-uuid', new ZonePlacement(new Point(13.405, 52.52), 750.0));

        $hiderTopic = $mercure->hiderLocationsTopic($game, $round);
        $zoneUpdates = self::updatesOfType($hub, 'zone');
        self::assertCount(1, $zoneUpdates);
        self::assertSame([$hiderTopic], $zoneUpdates[0]->getTopics());
        self::assertTrue($zoneUpdates[0]->isPrivate());

        $payload = self::decode($zoneUpdates[0]);
        self::assertSame(52.52, $payload['lat']);
        self::assertSame(13.405, $payload['lng']);
        self::assertEquals(750.0, $payload['radiusMeters']);
        self::assertSame($round->getUuid(), $payload['roundUuid']);

        foreach ($hub->published() as $update) {
            if (in_array($hiderTopic, $update->getTopics(), true)) {
                continue;
            }
            self::assertStringNotContainsString('13.405', $update->getData());
            self::assertStringNotContainsString('52.52', $update->getData());
            self::assertArrayNotHasKey('lat', self::decode($update));
            self::assertArrayNotHasKey('lng', self::decode($update));
        }
    }

    #[Test]
    public function itPublishesTheBareRadiusOnTheSeekerOnlyTopic(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hub = new FakeMercureHub();

        $this->hiderService($round, $hub)
            ->setZone($round->getUuid(), 'player-uuid', new ZonePlacement(new Point(13.405, 52.52), 750.0));

        $radiusUpdates = self::updatesOfType($hub, 'zone-radius');
        self::assertCount(1, $radiusUpdates);
        self::assertSame(
            [new MercureJwtService(self::SECRET)->seekerZoneTopic($game, $round)],
            $radiusUpdates[0]->getTopics(),
        );
        self::assertEquals(
            ['type' => 'zone-radius', 'radiusMeters' => 750.0],
            self::decode($radiusUpdates[0]),
        );
    }

    #[Test]
    public function itAnnouncesAFirstZonePlacedOnceSeekingHasStartedWithoutLeakingItsCoordinates(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            function (ChatMessage $message): bool {
                self::assertSame(ChatMessageType::System, $message->getType());
                self::assertSame('zone.moved_and_resized', $message->getBodyKey());
                self::assertSame(['radius' => '900 m'], $message->getBodyArgs());
                self::assertStringNotContainsString('13.405', (string) $message->getBody());
                self::assertStringNotContainsString('52.52', (string) $message->getBody());

                return true;
            },
        ));

        $this->hiderService($round, new FakeMercureHub(), null, $messages)
            ->setZone($round->getUuid(), 'player-uuid', new ZonePlacement(new Point(13.405, 52.52), 900.0));
    }

    #[Test]
    public function itAnnouncesTheRadiusInTheGameUnits(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Imperial);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            function (ChatMessage $message): bool {
                self::assertSame(['radius' => '1 mi'], $message->getBodyArgs());

                return true;
            },
        ));

        $this->hiderService($round, new FakeMercureHub(), null, $messages)
            ->setZone($round->getUuid(), 'player-uuid', new ZonePlacement(new Point(13.405, 52.52), 1609.344));
    }

    #[Test]
    public function itAnnouncesAFirstZoneOnceTheHidingPeriodHasElapsedEvenBeforeTheStatusFlips(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Hiding)->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 minute'));

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save');

        $this->hiderService($round, new FakeMercureHub(), null, $messages)
            ->setZone($round->getUuid(), 'player-uuid', new ZonePlacement(new Point(13.405, 52.52), 750.0));
    }

    /** The free placement window closes with the hiding period, cards take over. */
    #[Test]
    public function itRefusesToChangeAnExistingZoneOnceSeekingHasStarted(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);
        $existing = new HidingZone($round, new Point(13.0, 52.0), 750.0);

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Once the seekers are hunting, the hiding zone only changes by playing a card.');

        $this->hiderService($round, new FakeMercureHub(), $existing, $messages)
            ->setZone($round->getUuid(), 'player-uuid', new ZonePlacement(new Point(13.405, 52.52), 900.0));
    }

    #[Test]
    public function itRefusesTheChangeEvenWhenThePlacementMatchesTheZoneAlreadySet(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);
        $existing = new HidingZone($round, new Point(13.405, 52.52), 750.0);

        $this->expectException(FunctionalException::class);

        $this->hiderService($round, new FakeMercureHub(), $existing)
            ->setZone($round->getUuid(), 'player-uuid', new ZonePlacement(new Point(13.405, 52.52), 750.0));
    }

    #[Test]
    public function itStaysSilentWhileTheHidingPeriodIsStillRunning(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Hiding)->setHidingPeriodEndsAt(new \DateTimeImmutable('+30 minutes'));
        $existing = new HidingZone($round, new Point(13.0, 52.0), 750.0);

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');

        $this->hiderService($round, new FakeMercureHub(), $existing, $messages)
            ->setZone($round->getUuid(), 'player-uuid', new ZonePlacement(new Point(13.405, 52.52), 900.0));
    }

    #[Test]
    public function itStaysSilentInTheLobby(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $existing = new HidingZone($round, new Point(13.0, 52.0), 750.0);

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');

        $this->hiderService($round, new FakeMercureHub(), $existing, $messages)
            ->setZone($round->getUuid(), 'player-uuid', new ZonePlacement(new Point(13.405, 52.52), 900.0));
    }

    #[Test]
    public function itPostsNothingWhenTheZoneIsUnchangedDuringTheHidingPeriod(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Hiding)->setHidingPeriodEndsAt(new \DateTimeImmutable('+30 minutes'));
        $existing = new HidingZone($round, new Point(13.405, 52.52), 750.0);

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');

        $this->hiderService($round, new FakeMercureHub(), $existing, $messages)
            ->setZone($round->getUuid(), 'player-uuid', new ZonePlacement(new Point(13.405, 52.52), 750.0));
    }

    #[Test]
    public function itExpandsTheZoneByHalfWhenProsperousHomeIsPlayed(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);
        $existing = new HidingZone($round, new Point(13.405, 52.52), 500.0);

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            function (ChatMessage $message): bool {
                self::assertSame('zone.prosperous_home', $message->getBodyKey());
                self::assertStringNotContainsString('13.405', (string) $message->getBody());

                return true;
            },
        ));

        $zone = $this->hiderService($round, new FakeMercureHub(), $existing, $messages)
            ->playCard($round->getUuid(), 'player-uuid', ZoneCard::ProsperousHome, $this->cardPhoto());

        self::assertSame(750.0, $zone->getRadiusMeters());
    }

    #[Test]
    public function itHalvesTheZoneWhenTinyHomeIsPlayed(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);
        $existing = new HidingZone($round, new Point(13.405, 52.52), 500.0);

        $zone = $this->hiderService($round, new FakeMercureHub(), $existing)
            ->playCard($round->getUuid(), 'player-uuid', ZoneCard::TinyHome, $this->cardPhoto());

        self::assertSame(250.0, $zone->getRadiusMeters());
    }

    #[Test]
    public function itRefusesAZoneCardWhileTheHidingPeriodIsStillRunning(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Hiding)->setHidingPeriodEndsAt(new \DateTimeImmutable('+10 minutes'));
        $existing = new HidingZone($round, new Point(13.405, 52.52), 500.0);
        $service = $this->hiderService($round, new FakeMercureHub(), $existing);

        $this->expectException(FunctionalException::class);
        $service->playCard($round->getUuid(), 'player-uuid', ZoneCard::TinyHome, $this->cardPhoto());
    }

    #[Test]
    public function itRefusesEveryCardButProsperousHomeOnceTheEndgameHasStarted(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking)->setEndgameStartedAt(new \DateTimeImmutable());
        $existing = new HidingZone($round, new Point(13.405, 52.52), 500.0);

        $expanded = $this->hiderService($round, new FakeMercureHub(), $existing)
            ->playCard($round->getUuid(), 'player-uuid', ZoneCard::ProsperousHome, $this->cardPhoto());
        self::assertSame(750.0, $expanded->getRadiusMeters());

        $this->expectException(FunctionalException::class);
        $this->hiderService($round, new FakeMercureHub(), $existing)
            ->playCard($round->getUuid(), 'player-uuid', ZoneCard::Move, $this->cardPhoto());
    }

    #[Test]
    public function itRefusesAZoneCardBeforeAZoneExists(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);
        $service = $this->hiderService($round, new FakeMercureHub());

        $this->expectException(FunctionalException::class);
        $service->playCard($round->getUuid(), 'player-uuid', ZoneCard::ProsperousHome, $this->cardPhoto());
    }

    #[Test]
    public function itOpensAMovePeriodAndBanksTheSeekingTimeAlreadyEarned(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking)
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('-12 minutes'));
        $existing = new HidingZone($round, new Point(13.405, 52.52), 500.0);

        $existing->setStationName('Alexanderplatz');
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            function (ChatMessage $message): bool {
                self::assertSame('zone.move_played_from', $message->getBodyKey());
                self::assertSame(['min' => 20, 'station' => 'Alexanderplatz'], $message->getBodyArgs());

                return true;
            },
        ));

        $this->hiderService($round, new FakeMercureHub(), $existing, $messages)
            ->playCard($round->getUuid(), 'player-uuid', ZoneCard::Move, $this->cardPhoto());

        self::assertTrue($round->isInMovePeriod());
        self::assertSame(RoundStatus::Hiding, $round->getStatus());
        self::assertSame(500.0, $existing->getRadiusMeters());
        self::assertGreaterThanOrEqual(12 * 60, $round->getBankedSeekingSeconds());
    }

    #[Test]
    public function itKeepsTheDesignatedStationNameForMoveToReveal(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $existing = new HidingZone($round, new Point(13.0, 52.0), 500.0);
        $existing->setStationName('Hauptbahnhof');

        $zone = $this->hiderService($round, new FakeMercureHub(), $existing)->setZone(
            $round->getUuid(),
            'player-uuid',
            new ZonePlacement(new Point(13.405, 52.52), 500.0, 'Alexanderplatz'),
        );

        self::assertSame('Alexanderplatz', $zone->getStationName());
    }

    #[Test]
    public function aRadiusOnlyChangeLeavesTheStationNameAlone(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $existing = new HidingZone($round, new Point(13.405, 52.52), 500.0);
        $existing->setStationName('Alexanderplatz');

        $zone = $this->hiderService($round, new FakeMercureHub(), $existing)->setZone(
            $round->getUuid(),
            'player-uuid',
            new ZonePlacement(new Point(13.405, 52.52), 750.0),
        );

        self::assertSame('Alexanderplatz', $zone->getStationName());
        self::assertSame(750.0, $zone->getRadiusMeters());
    }

    private function cardPhoto(): UploadedFile
    {
        return $this->createStub(UploadedFile::class);
    }

    private function hiderService(
        Round $round,
        HubInterface $hub,
        ?HidingZone $existing = null,
        ?ChatMessageRepository $messages = null,
    ): HidingZoneService {
        $player = new Player($round->getGame(), AccountFactory::create('Alice', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn($player);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')
            ->willReturn(new RoundMembership($round, $player, Side::Hider));
        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn($existing);

        return $this->service($rounds, $players, $memberships, $zones, $hub, $messages);
    }

    /**
     * @return list<Update>
     */
    private static function updatesOfType(FakeMercureHub $hub, string $type): array
    {
        return array_values(array_filter(
            $hub->published(),
            static fn (Update $update): bool => self::decode($update)['type'] === $type,
        ));
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
