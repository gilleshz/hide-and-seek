<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ScoreBonus;
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
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameRepository;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\RoundTiming;
use App\Service\ChatService;
use App\Service\MercureJwtService;
use App\Service\RoundService;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(RoundService::class)]
final class RoundServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    private function service(
        RoundRepository $rounds,
        HubInterface $hub,
        ?ChatService $chat = null,
        ?LoggerInterface $logger = null,
        ?PlayerRepository $players = null,
        ?RoundMembershipRepository $memberships = null,
        ?GameRepository $games = null,
        ?HidingZoneRepository $zones = null,
    ): RoundService {
        return new RoundService(
            $rounds,
            $games ?? $this->createStub(GameRepository::class),
            $memberships ?? $this->createStub(RoundMembershipRepository::class),
            $players ?? $this->stubPlayers(),
            $zones ?? $this->createStub(HidingZoneRepository::class),
            $chat ?? $this->chatStub($hub),
            new MercureJwtService(self::SECRET),
            $hub,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    private function stubPlayers(): PlayerRepository
    {
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findByGameOrdered')->willReturn([]);

        return $players;
    }

    private function chatStub(HubInterface $hub): ChatService
    {
        return $this->chatWith($this->createStub(ChatMessageRepository::class), $hub);
    }

    private function chatWith(ChatMessageRepository $messages, HubInterface $hub): ChatService
    {
        return new ChatService(
            $messages,
            new MercureJwtService(self::SECRET),
            $hub,
            $this->createStub(ImageStorageInterface::class),
        );
    }

    #[Test]
    public function theTimerEventCarriesTheWholeRoundStateWithoutTheZoneCoordinates(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $zone = new HidingZone($round, new Point(13.405, 52.52), 750.0);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn($zone);

        $hub = new FakeMercureHub();
        $this->service($rounds, $hub, zones: $zones)->start($round->getUuid());

        $payload = json_decode($hub->published()[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertTrue($payload['hasHidingZone']);
        self::assertEqualsWithDelta(750.0, $payload['hidingRadiusMeters'], 0.001);
        self::assertNull($payload['hidingTimeSeconds']);
        self::assertNull($payload['scoreSeconds']);
        self::assertSame(0, $payload['bankedSeekingSeconds']);
        self::assertFalse($payload['inMovePeriod']);
        self::assertArrayNotHasKey('lat', $payload);
        self::assertArrayNotHasKey('lng', $payload);
    }

    #[Test]
    public function theTimerEventFallsBackToTheGameDefaultRadiusWhenNoZoneExists(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);

        $hub = new FakeMercureHub();
        $this->service($rounds, $hub)->start($round->getUuid());

        $payload = json_decode($hub->published()[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertFalse($payload['hasHidingZone']);
        self::assertEqualsWithDelta(
            RoundTiming::defaultRadiusMeters(GameSize::Small, Edition::Metric),
            $payload['hidingRadiusMeters'],
            0.001,
        );
    }

    #[Test]
    public function itStartsALobbyRoundAndPublishesTheTimer(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);

        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $rounds->expects(self::once())->method('save');

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->with(self::callback(
            function (Update $update) use ($round): bool {
                $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($payload);
                self::assertSame('hiding', $payload['status']);
                self::assertSame($round->getUuid(), $payload['roundUuid']);
                return true;
            },
        ));

        $service = $this->service($rounds, $hub);
        $started = $service->start($round->getUuid());

        self::assertSame(RoundStatus::Hiding, $started->getStatus());
        self::assertNotNull($started->getHidingPeriodStartedAt());
        self::assertNotNull($started->getHidingPeriodEndsAt());
    }

    #[Test]
    public function itPublishesTheTimerWhenCreatingTheNextRound(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);

        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findActiveByGame')->willReturn(null);
        $rounds->method('findLatestByGame')->willReturn(null);
        $rounds->expects(self::exactly(2))->method('save');

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $publishedUuid = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->with(self::callback(
            function (Update $update) use (&$publishedUuid): bool {
                $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($payload);
                self::assertSame('lobby', $payload['status']);
                $publishedUuid = $payload['roundUuid'];
                return true;
            },
        ));

        $created = $this->service($rounds, $hub, games: $games)->createNextRound('game-uuid');

        self::assertSame(RoundStatus::Lobby, $created->getStatus());
        self::assertSame($created->getUuid(), $publishedUuid);
    }

    #[Test]
    public function itWarnsOnStartWhenAPlayerHasNotChosenASide(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $sideless = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findByGameOrdered')->willReturn([$sideless]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('not chosen a side'),
            self::callback(
                function (array $context) use ($round): bool {
                    self::assertSame($round->getUuid(), $context['roundUuid']);
                    self::assertSame(['Bob'], $context['players']);
                    return true;
                },
            ),
        );

        $started = $this->service($rounds, $this->createStub(HubInterface::class), logger: $logger, players: $players)
            ->start($round->getUuid());

        self::assertSame(RoundStatus::Hiding, $started->getStatus());
    }

    #[Test]
    public function itDoesNotWarnOnStartWhenEveryPlayerHasASide(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findByGameOrdered')->willReturn([$player]);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findByRound')->willReturn([new RoundMembership($round, $player, Side::Hider)]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->service(
            $rounds,
            $this->createStub(HubInterface::class),
            logger: $logger,
            players: $players,
            memberships: $memberships,
        )->start($round->getUuid());
    }

    #[Test]
    public function itRejectsStartingARoundThatIsAlreadyActive(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Imperial);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Hiding);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);

        $this->expectException(FunctionalException::class);
        $this->service($rounds, $this->createStub(HubInterface::class))->start($round->getUuid());
    }

    #[Test]
    public function itThrowsEntityNotFoundWhenStartingAnUnknownRound(): void
    {
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);
        $this->service($rounds, $this->createStub(HubInterface::class))->start('nonexistent');
    }

    #[Test]
    public function itReturnsHidingWhenTheHidingPeriodHasNotElapsedAndSeekingWhenItHas(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $expired = new Round($game);
        $expired->setStatus(RoundStatus::Hiding)->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 minute'));
        $active = new Round($game);
        $active->setStatus(RoundStatus::Hiding)->setHidingPeriodEndsAt(new \DateTimeImmutable('+1 hour'));

        $rounds = $this->createStub(RoundRepository::class);
        $hub = $this->createStub(HubInterface::class);
        $service = $this->service($rounds, $hub);

        self::assertSame(RoundStatus::Seeking, $service->currentStatus($expired));
        self::assertSame(RoundStatus::Hiding, $service->currentStatus($active));
    }

    #[Test]
    public function itPublishesTimerExactlyOnceWhenCurrentStatusFlipsHidingToSeeking(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $expired = new Round($game);
        $expired->setStatus(RoundStatus::Hiding)->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 minute'));
        $stillHiding = new Round($game);
        $stillHiding->setStatus(RoundStatus::Hiding)->setHidingPeriodEndsAt(new \DateTimeImmutable('+1 hour'));

        $rounds = $this->createStub(RoundRepository::class);
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish');

        $service = $this->service($rounds, $hub);
        $service->currentStatus($expired);
        $service->currentStatus($stillHiding);
    }

    #[Test]
    public function itBanksTheEarnedSeekingTimeWhenAMovePeriodOpens(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = new Round($game);
        $round
            ->setStatus(RoundStatus::Seeking)
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('-25 minutes'));

        $rounds = $this->createMock(RoundRepository::class);
        $rounds->expects(self::once())->method('save');

        $this->service($rounds, $this->createStub(HubInterface::class))->openMovePeriod($round);

        self::assertSame(RoundStatus::Hiding, $round->getStatus());
        self::assertTrue($round->isInMovePeriod());
        self::assertGreaterThanOrEqual(25 * 60, $round->getBankedSeekingSeconds());
        // Large games get the 60 minutes printed on the card.
        self::assertGreaterThan(new \DateTimeImmutable('+59 minutes'), $round->getHidingPeriodEndsAt());
    }

    #[Test]
    public function itAnnouncesTheEndOfAMovePeriodWhenSeekingResumes(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $round
            ->setStatus(RoundStatus::Hiding)
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 second'))
            ->setInMovePeriod(true);

        $hub = $this->createStub(HubInterface::class);
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            function (ChatMessage $message): bool {
                self::assertSame('round.move_period_over', $message->getBodyKey());

                return true;
            },
        ));

        $service = $this->service($this->createStub(RoundRepository::class), $hub, $this->chatWith($messages, $hub));

        self::assertSame(RoundStatus::Seeking, $service->currentStatus($round));
        self::assertFalse($round->isInMovePeriod());
    }

    #[Test]
    public function itAddsTheBankedSecondsToTheHidingTimeItReports(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $round
            ->setStatus(RoundStatus::Seeking)
            ->setHidingPeriodStartedAt(new \DateTimeImmutable('2026-01-01T10:00:00Z'))
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('2026-01-01T10:30:00Z'))
            ->setBankedSeekingSeconds(600);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);

        $hub = $this->createStub(HubInterface::class);
        $posted = null;
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            function (ChatMessage $message) use (&$posted): bool {
                self::assertSame('round.hiding_time', $message->getBodyKey());
                $posted = $message->getBodyArgs()['seconds'] ?? null;

                return true;
            },
        ));

        $stopped = $this->service($rounds, $hub, $this->chatWith($messages, $hub))
            ->stop($round->getUuid(), caught: true);

        $endsAt = $stopped->getHidingPeriodEndsAt();
        $endedAt = $stopped->getSeekingEndedAt();
        self::assertNotNull($endsAt);
        self::assertNotNull($endedAt);
        self::assertSame(600 + $endedAt->getTimestamp() - $endsAt->getTimestamp(), $posted);
    }

    /**
     * The posted total is the number the leaderboard ranks, so a sprung trap reaches chat even with no
     * declared bonus, and stays outside the percentage base.
     */
    #[Test]
    public function itPostsTheTrapMinutesInTheTotalEvenWithNoDeclaredBonus(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $round
            ->setStatus(RoundStatus::Seeking)
            ->setHidingPeriodStartedAt(new \DateTimeImmutable('-1 hour'))
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('-60 minutes'))
            ->addTrapBonusSeconds(360);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);

        $hub = $this->createStub(HubInterface::class);
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            function (ChatMessage $message) use ($round): bool {
                $args = $message->getBodyArgs() ?? [];
                self::assertSame('round.hiding_time_with_bonus', $message->getBodyKey());
                self::assertSame(0, $args['bonusMin'] ?? null);
                self::assertSame(0, $args['percent'] ?? null);
                self::assertSame(6, $args['trapMin'] ?? null);
                self::assertSame($round->getScoreSeconds(), $args['totalSeconds'] ?? null);
                self::assertSame(66, intdiv((int) $args['totalSeconds'], 60));

                return true;
            },
        ));

        $this->service($rounds, $hub, $this->chatWith($messages, $hub))
            ->stop($round->getUuid(), caught: true);
    }

    /** The declared bonus keys travel in a fixed order the client resolves positionally. */
    #[Test]
    public function itKeepsTheScoreArgumentOrderTheClientResolvesPositionally(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $round
            ->setStatus(RoundStatus::Seeking)
            ->setHidingPeriodStartedAt(new \DateTimeImmutable('-1 hour'))
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('-60 minutes'))
            ->addTrapBonusSeconds(120);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);

        $hub = $this->createStub(HubInterface::class);
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            function (ChatMessage $message): bool {
                self::assertSame(
                    ['seconds', 'bonusMin', 'percent', 'trapMin', 'totalSeconds'],
                    array_keys($message->getBodyArgs() ?? []),
                );

                return true;
            },
        ));

        $this->service($rounds, $hub, $this->chatWith($messages, $hub))
            ->stop($round->getUuid(), new ScoreBonus(minutes: 5, percent: 10), caught: true);
    }

    #[Test]
    public function itReportsTheDeclaredTimeBonusesAlongsideTheRawHidingTime(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $round
            ->setStatus(RoundStatus::Seeking)
            ->setHidingPeriodStartedAt(new \DateTimeImmutable('-1 hour'))
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('-60 minutes'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);

        $hub = $this->createStub(HubInterface::class);
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            function (ChatMessage $message): bool {
                $args = $message->getBodyArgs() ?? [];
                self::assertSame('round.hiding_time_with_bonus', $message->getBodyKey());
                self::assertSame(15, $args['bonusMin'] ?? null);
                self::assertSame(20, $args['percent'] ?? null);
                self::assertSame(0, $args['trapMin'] ?? null);
                // 60 min raw + 15 flat + 20% of 60 = 87 min, in seconds because the wording is the client's.
                self::assertIsInt($args['totalSeconds'] ?? null);
                self::assertSame(87, intdiv($args['totalSeconds'], 60));

                return true;
            },
        ));

        $stopped = $this->service($rounds, $hub, $this->chatWith($messages, $hub))
            ->stop($round->getUuid(), new ScoreBonus(minutes: 15, percent: 20), caught: true);

        self::assertSame(15, $stopped->getBonusMinutes());
        self::assertSame(20, $stopped->getBonusPercent());
    }

    #[Test]
    public function itScoresTheFrozenHidingTimeRatherThanTheTimeTheRequestArrives(): void
    {
        $round = $this->seekingRoundStartedMinutesAgo(60);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $hub = $this->createStub(HubInterface::class);
        $messages = $this->createStub(ChatMessageRepository::class);

        $stopped = $this->service($rounds, $hub, $this->chatWith($messages, $hub))
            ->stop($round->getUuid(), declaredSeconds: 30 * 60, caught: true);

        $endsAt = $stopped->getHidingPeriodEndsAt();
        $endedAt = $stopped->getSeekingEndedAt();
        self::assertNotNull($endsAt);
        self::assertNotNull($endedAt);
        self::assertSame(30 * 60, $endedAt->getTimestamp() - $endsAt->getTimestamp());
    }

    #[Test]
    public function itIgnoresADeclaredHidingTimeLongerThanTheOneThatElapsed(): void
    {
        $round = $this->seekingRoundStartedMinutesAgo(10);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $hub = $this->createStub(HubInterface::class);
        $messages = $this->createStub(ChatMessageRepository::class);

        $stopped = $this->service($rounds, $hub, $this->chatWith($messages, $hub))
            ->stop($round->getUuid(), declaredSeconds: 9 * 3600, caught: true);

        $endsAt = $stopped->getHidingPeriodEndsAt();
        $endedAt = $stopped->getSeekingEndedAt();
        self::assertNotNull($endsAt);
        self::assertNotNull($endedAt);
        self::assertLessThanOrEqual(10 * 60 + 1, $endedAt->getTimestamp() - $endsAt->getTimestamp());
    }

    private function seekingRoundStartedMinutesAgo(int $minutes): Round
    {
        $round = new Round(new Game('Berlin', GameSize::Small, Edition::Metric));

        return $round
            ->setStatus(RoundStatus::Seeking)
            ->setHidingPeriodStartedAt(new \DateTimeImmutable("-{$minutes} minutes -30 minutes"))
            ->setHidingPeriodEndsAt(new \DateTimeImmutable("-{$minutes} minutes"));
    }

    #[Test]
    public function itStopsAHidingRoundWithoutPostingAHidingTime(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $round
            ->setStatus(RoundStatus::Hiding)
            ->setHidingPeriodStartedAt(new \DateTimeImmutable())
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('+1 hour'));

        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $rounds->expects(self::once())->method('save');

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish');

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');

        $stopped = $this->service($rounds, $hub, $this->chatWith($messages, $hub))
            ->stop($round->getUuid());

        self::assertSame(RoundStatus::Ended, $stopped->getStatus());
        self::assertNull($stopped->getSeekingEndedAt());
    }

    #[Test]
    public function itAbortsASeekingRoundWithoutScoringItOrPostingAHidingTime(): void
    {
        $round = $this->seekingRoundStartedMinutesAgo(20);

        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $rounds->expects(self::once())->method('save');

        $hub = $this->createStub(HubInterface::class);
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');

        $stopped = $this->service($rounds, $hub, $this->chatWith($messages, $hub))->stop($round->getUuid());

        self::assertSame(RoundStatus::Ended, $stopped->getStatus());
        self::assertNull($stopped->getSeekingEndedAt());
        self::assertFalse($stopped->isCaught());
        self::assertNull($stopped->getHiderNames());
        self::assertNull($stopped->getHidingTimeSeconds());
    }

    #[Test]
    public function itScoresACaughtStopAndSnapshotsTheHidingTeam(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $round
            ->setStatus(RoundStatus::Seeking)
            ->setHidingPeriodStartedAt(new \DateTimeImmutable('-90 minutes'))
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('-60 minutes'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findHidersByRound')->willReturn([
            new RoundMembership($round, new Player($game, AccountFactory::create('Alice', 'test-password')), Side::Hider),
            new RoundMembership($round, new Player($game, AccountFactory::create('Bob', 'test-password')), Side::Hider),
        ]);

        $hub = $this->createStub(HubInterface::class);
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            function (ChatMessage $message): bool {
                self::assertSame('round.hiding_time', $message->getBodyKey());

                return true;
            },
        ));

        $stopped = $this->service($rounds, $hub, $this->chatWith($messages, $hub), memberships: $memberships)
            ->stop($round->getUuid(), caught: true);

        self::assertTrue($stopped->isCaught());
        self::assertNotNull($stopped->getSeekingEndedAt());
        self::assertSame(['Alice', 'Bob'], $stopped->getHiderNames());
        self::assertGreaterThanOrEqual(60 * 60, $stopped->getHidingTimeSeconds());
    }

    #[Test]
    public function itRejectsStoppingALobbyRound(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);

        $this->expectException(FunctionalException::class);
        $this->service($rounds, $this->createStub(HubInterface::class))->stop($round->getUuid());
    }

    #[Test]
    public function itStopsASeekingRoundAndPostsTheHidingTimeAsASystemMessage(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $round
            ->setStatus(RoundStatus::Seeking)
            ->setHidingPeriodStartedAt(new \DateTimeImmutable('2026-01-01T10:00:00Z'))
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('2026-01-01T10:30:00Z'));

        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $rounds->expects(self::once())->method('save');

        $hub = $this->createMock(HubInterface::class);
        $published = [];
        $hub->expects(self::atLeastOnce())->method('publish')->willReturnCallback(
            function (Update $update) use (&$published): string {
                $published[] = $update;
                return '';
            },
        );

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            function (ChatMessage $message): bool {
                self::assertSame(ChatMessageType::System, $message->getType());
                self::assertNull($message->getSender());
                self::assertNotNull($message->getBody());
                self::assertStringContainsString('Hiding time:', (string) $message->getBody());
                return true;
            },
        ));

        $stopped = $this->service($rounds, $hub, $this->chatWith($messages, $hub))
            ->stop($round->getUuid(), caught: true);

        self::assertSame(RoundStatus::Ended, $stopped->getStatus());
        self::assertNotNull($stopped->getSeekingEndedAt());
        self::assertCount(2, $published);
        self::assertStringContainsString('ended', $published[1]->getData());
    }
}
