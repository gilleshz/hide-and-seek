<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Entity\Player;
use App\Enum\ChatMessageType;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Exception\EntityNotFoundException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\ChatService;
use App\Service\LeaveService;
use App\Service\MercureJwtService;
use App\Service\RosterNotifier;
use App\Service\RosterService;
use App\Storage\ImageStorageInterface;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(LeaveService::class)]
final class LeaveServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function itAnnouncesTheDepartureAndMarksThePlayerWithoutDeletingThem(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Charlie', 'test-password'));

        $posted = null;
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChatMessage $message) use (&$posted): void {
                $posted = $message;
            });

        $players = $this->createMock(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn($player);
        $players->expects(self::once())->method('save');
        $players->expects(self::never())->method('remove');

        $memberships = $this->createMock(RoundMembershipRepository::class);
        $memberships->expects(self::once())->method('removeByPlayer')->with($player);

        $this->serviceFor($game, $players, $messages, $memberships)->leave('game-uuid', 'player-uuid');

        self::assertTrue($player->hasLeft());
        self::assertInstanceOf(ChatMessage::class, $posted);
        self::assertSame(ChatMessageType::System, $posted->getType());
        self::assertSame('system.player_left', $posted->getBodyKey());
        self::assertSame(['name' => 'Charlie'], $posted->getBodyArgs());
    }

    #[Test]
    public function itThrowsWhenThePlayerIsNotInTheGame(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn(null);

        $service = $this->serviceFor($game, $players, $this->createStub(ChatMessageRepository::class));

        self::expectException(EntityNotFoundException::class);
        $service->leave('game-uuid', 'unknown-player');
    }

    #[Test]
    public function aHostRemovalAnnouncesPlayerRemoved(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Charlie', 'test-password'));

        $posted = null;
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChatMessage $message) use (&$posted): void {
                $posted = $message;
            });

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn($player);

        $this->serviceFor($game, $players, $messages)->remove('game-uuid', 'player-uuid', 'host-uuid');

        self::assertInstanceOf(ChatMessage::class, $posted);
        self::assertSame('system.player_removed', $posted->getBodyKey());
        self::assertSame(['name' => 'Charlie'], $posted->getBodyArgs());
    }

    #[Test]
    public function aSelfRemovalIsAnnouncedAsALeave(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Charlie', 'test-password'));

        $posted = null;
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChatMessage $message) use (&$posted): void {
                $posted = $message;
            });

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuid')->willReturn($player);

        $this->serviceFor($game, $players, $messages)->remove('game-uuid', $player->getUuid(), $player->getUuid());

        self::assertInstanceOf(ChatMessage::class, $posted);
        self::assertSame('system.player_left', $posted->getBodyKey());
        self::assertSame(['name' => 'Charlie'], $posted->getBodyArgs());
    }

    private function serviceFor(
        Game $game,
        PlayerRepository $players,
        ChatMessageRepository $messages,
        ?RoundMembershipRepository $memberships = null,
    ): LeaveService {
        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $mercure = new MercureJwtService(self::SECRET);
        $hub = $this->createStub(HubInterface::class);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $work): mixed => $work());

        return new LeaveService(
            $games,
            $players,
            new RosterNotifier($hub, $mercure, $this->emptyRosterService()),
            new ChatService($messages, $mercure, $hub, $this->createStub(ImageStorageInterface::class)),
            $memberships ?? $this->createStub(RoundMembershipRepository::class),
            $entityManager,
        );
    }

    private function emptyRosterService(): RosterService
    {
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findByGameOrdered')->willReturn([]);

        return new RosterService(
            $players,
            $this->createStub(RoundRepository::class),
            $this->createStub(RoundMembershipRepository::class),
        );
    }
}
