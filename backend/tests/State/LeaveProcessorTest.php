<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use App\Entity\Game;
use App\Entity\Player;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Exception\EntityNotFoundException;
use App\Exception\IdentityRequiredException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\ChatService;
use App\Service\IdentityResolver;
use App\Service\LeaveService;
use App\Service\MercureJwtService;
use App\Service\RosterNotifier;
use App\Service\RosterService;
use App\State\LeaveProcessor;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(LeaveProcessor::class)]
final class LeaveProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function theCallerLeavesWithTheirOwnToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn($player);

        $processor = $this->processor($game, $player, $games, $players);
        $resource = $processor->process(null, new Post(), ['gameUuid' => $game->getUuid()]);

        self::assertSame($game->getUuid(), $resource->gameUuid);
        self::assertSame($player->getUuid(), $resource->playerUuid);
        self::assertTrue($resource->removed);
        self::assertTrue($player->hasLeft());
    }

    #[Test]
    public function itCannotLeaveAsAnotherPlayerBecauseIdentityComesFromTheToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $other = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $caller = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn($caller);

        $processor = $this->processor($game, $caller, $games, $players);
        $resource = $processor->process(null, new Post(), ['gameUuid' => $game->getUuid()]);

        self::assertSame($caller->getUuid(), $resource->playerUuid);
        self::assertFalse($other->hasLeft());
        self::assertTrue($caller->hasLeft());
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);

        $this->expectException(IdentityRequiredException::class);

        $this->processor($game, null, $this->createStub(GameRepository::class), $this->createStub(PlayerRepository::class), withHeader: false)
            ->process(null, new Post(), ['gameUuid' => $game->getUuid()]);
    }

    #[Test]
    public function itRejectsAnUnknownGame(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->processor($game, $player, $games, $this->createStub(PlayerRepository::class))
            ->process(null, new Post(), ['gameUuid' => $game->getUuid()]);
    }

    private function processor(
        Game $game,
        ?Player $player,
        GameRepository $games,
        PlayerRepository $players,
        bool $withHeader = true,
    ): LeaveProcessor {
        $mercure = new MercureJwtService(self::SECRET);
        $hub = new FakeMercureHub();
        $rounds = $this->createStub(RoundRepository::class);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $rosterPlayers = $this->createStub(PlayerRepository::class);
        $rosterPlayers->method('findByGameOrdered')->willReturn([]);

        $stack = new RequestStack();
        $request = new Request();
        if ($withHeader && $player !== null) {
            $request->headers->set(IdentityResolver::HEADER, $mercure->issueSubscriberToken([], $player->getUuid()));
        }
        $stack->push($request);
        $identityPlayers = $this->createStub(PlayerRepository::class);
        $identityPlayers->method('findOneByUuidIncludingLeft')->willReturn($player);

        $chat = new ChatService(
            $this->createStub(ChatMessageRepository::class),
            $mercure,
            $hub,
            $this->createStub(ImageStorageInterface::class),
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work(),
        );

        $leaveService = new LeaveService(
            $games,
            $players,
            new RosterNotifier($hub, $mercure, new RosterService($rosterPlayers, $rounds, $memberships)),
            $chat,
            $memberships,
            $entityManager,
        );

        return new LeaveProcessor($leaveService, new IdentityResolver($mercure, $identityPlayers, $stack));
    }
}
