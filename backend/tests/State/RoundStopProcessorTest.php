<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use App\Dto\RoundStopInput;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Exception\IdentityRequiredException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameRepository;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\ChatService;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Service\RoundService;
use App\State\RoundStopProcessor;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(RoundStopProcessor::class)]
final class RoundStopProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function aPlayerOfTheGameStopsTheRunningRoundWithTheirToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking)->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 hour'));
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $rounds->expects(self::once())->method('save')->with($round);

        $stop = new RoundStopInput();
        $stop->bonusMinutes = 2;
        $stop->hidingSeconds = 3600;
        $stop->caught = true;

        $processor = $this->processor($round, $player, $rounds);
        $resource = $processor->process($stop, new Post(), ['roundUuid' => $round->getUuid()]);

        self::assertSame(RoundStatus::Ended, $resource->status);
        self::assertTrue($resource->caught);
    }

    #[Test]
    public function aSeekerCannotDeclareAScoredStop(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking)->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 hour'));
        $seeker = new Player($game, AccountFactory::create('Sam', 'test-password'));

        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $rounds->expects(self::never())->method('save');

        $stop = new RoundStopInput();
        $stop->caught = true;

        try {
            $this->processor($round, $seeker, $rounds, side: Side::Seeker)
                ->process($stop, new Post(), ['roundUuid' => $round->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('round.not_hider', $e->getErrorKey());
        }
    }

    #[Test]
    public function anyMemberMayAbortTheRoundWithoutScoring(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking)->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 hour'));
        $seeker = new Player($game, AccountFactory::create('Sam', 'test-password'));

        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $rounds->expects(self::once())->method('save')->with($round);

        $resource = $this->processor($round, $seeker, $rounds, side: Side::Seeker)
            ->process(new RoundStopInput(), new Post(), ['roundUuid' => $round->getUuid()]);

        self::assertSame(RoundStatus::Ended, $resource->status);
        self::assertFalse($resource->caught);
    }

    #[Test]
    public function itRefusesATokenPlayerFromAnotherGame(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $otherGame = new Game('Paris', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $player = new Player($otherGame, AccountFactory::create('Eve', 'test-password'));

        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $rounds->expects(self::never())->method('save');

        try {
            $this->processor($round, $player, $rounds)
                ->process(new RoundStopInput(), new Post(), ['roundUuid' => $round->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('round.player_wrong_game', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRefusesARoundThatIsNotRunning(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $rounds = $this->createMock(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $rounds->expects(self::never())->method('save');

        try {
            $this->processor($round, $player, $rounds)
                ->process(new RoundStopInput(), new Post(), ['roundUuid' => $round->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('round.not_running', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);

        $this->expectException(IdentityRequiredException::class);

        $this->processor($round, null, $rounds, withHeader: false)
            ->process(new RoundStopInput(), new Post(), ['roundUuid' => $round->getUuid()]);
    }

    #[Test]
    public function itRejectsAnUnknownRound(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->processor($round, $player, $rounds)
            ->process(new RoundStopInput(), new Post(), ['roundUuid' => '00000000-0000-0000-0000-000000000000']);
    }

    private function processor(
        Round $round,
        ?Player $player,
        RoundRepository $rounds,
        bool $withHeader = true,
        Side $side = Side::Hider,
    ): RoundStopProcessor {
        $mercure = new MercureJwtService(self::SECRET);
        $hub = new FakeMercureHub();
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(
            $player === null ? null : new RoundMembership($round, $player, $side),
        );
        $memberships->method('findHidersByRound')->willReturn([]);

        $stack = new RequestStack();
        $request = new Request();
        if ($withHeader && $player !== null) {
            $request->headers->set(IdentityResolver::HEADER, $mercure->issueSubscriberToken([], $player->getUuid()));
        }
        $stack->push($request);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $zones = $this->createStub(HidingZoneRepository::class);
        $chat = new ChatService(
            $this->createStub(ChatMessageRepository::class),
            $mercure,
            $hub,
            $this->createStub(ImageStorageInterface::class),
        );

        $roundService = new RoundService(
            $rounds,
            $this->createStub(GameRepository::class),
            $memberships,
            $players,
            $zones,
            $chat,
            $mercure,
            $hub,
            $this->createStub(LoggerInterface::class),
        );

        return new RoundStopProcessor(
            $roundService,
            $rounds,
            new IdentityResolver($mercure, $players, $stack),
            $memberships,
        );
    }
}
