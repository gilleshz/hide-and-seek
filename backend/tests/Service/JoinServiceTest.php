<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\ChatMessageType;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\ErrorKey;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\AccountRepository;
use App\Repository\ChatMessageRepository;
use App\Repository\GameRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\ChatService;
use App\Service\JoinService;
use App\Service\MercureJwtService;
use App\Service\RosterNotifier;
use App\Service\RosterService;
use App\Storage\ImageStorageInterface;
use App\Tests\Support\AccountFactory;
use Doctrine\DBAL\Driver\PDO\Exception as PdoException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(JoinService::class)]
final class JoinServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';
    private const string PASSWORD = 'correct-password';

    private function silentRoster(): RosterNotifier
    {
        return new RosterNotifier(
            $this->createStub(HubInterface::class),
            new MercureJwtService(self::SECRET),
            $this->emptyRosterService(),
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

    private function silentChat(?ChatMessageRepository $messages = null): ChatService
    {
        return new ChatService(
            $messages ?? $this->createStub(ChatMessageRepository::class),
            new MercureJwtService(self::SECRET),
            $this->createStub(HubInterface::class),
            $this->createStub(ImageStorageInterface::class),
        );
    }

    /** The service wraps the join in a transaction; the test doubles must run the closure. */
    private function transactionalEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function service(
        GameRepository $games,
        PlayerRepository $players,
        AccountRepository $accounts,
        ?RosterNotifier $roster = null,
        ?ChatService $chat = null,
    ): JoinService {
        return new JoinService(
            $games,
            $this->createStub(RoundRepository::class),
            $players,
            $accounts,
            $this->transactionalEntityManager(),
            $roster ?? $this->silentRoster(),
            $chat ?? $this->silentChat(),
        );
    }

    private function game(): Game
    {
        return new Game('Berlin', GameSize::Small, Edition::Metric);
    }

    #[Test]
    public function itReconnectsToTheExistingPlayerForTheSameAccountWithThePassword(): void
    {
        $game = $this->game();
        $account = AccountFactory::create('Alice', self::PASSWORD);
        $existing = new Player($game, $account);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createMock(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn($existing);
        $players->expects(self::never())->method('save');

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn($account);

        $result = $this->service($games, $players, $accounts)->join('game-key', 'Alice', self::PASSWORD);

        self::assertSame($existing, $result->player);
        self::assertFalse($result->isNew);
    }

    #[Test]
    public function itRequiresThePasswordToReconnectAnExistingName(): void
    {
        $game = $this->game();
        $account = AccountFactory::create('Alice', self::PASSWORD);
        $existing = new Player($game, $account);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn($existing);

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn($account);

        try {
            $this->service($games, $players, $accounts)->join('game-key', 'Alice');
            self::fail('Expected a FunctionalException for a missing password.');
        } catch (FunctionalException $e) {
            self::assertSame(ErrorKey::JOIN_PASSWORD_REQUIRED, $e->getErrorKey());
            self::assertSame(
                "This name is already used by another player. If it's yours, enter its password. Otherwise, pick a different name.",
                $e->getMessage(),
            );
        }
    }

    #[Test]
    public function itRejectsAWrongPasswordForAnExistingName(): void
    {
        $game = $this->game();
        $account = AccountFactory::create('Alice', self::PASSWORD);
        $existing = new Player($game, $account);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn($existing);

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn($account);

        try {
            $this->service($games, $players, $accounts)->join('game-key', 'Alice', 'wrong-password');
            self::fail('Expected a FunctionalException for a wrong password.');
        } catch (FunctionalException $e) {
            self::assertSame(ErrorKey::JOIN_PASSWORD_INVALID, $e->getErrorKey());
            self::assertSame(
                "Wrong password for this name. If it's not your name, pick a different one.",
                $e->getMessage(),
            );
        }
    }

    #[Test]
    public function itRequiresAPasswordToRegisterANewName(): void
    {
        $game = $this->game();

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn(null);

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn(null);

        try {
            $this->service($games, $players, $accounts)->join('game-key', 'Bob');
            self::fail('Expected a FunctionalException for a missing password.');
        } catch (FunctionalException $e) {
            self::assertSame(ErrorKey::JOIN_PASSWORD_REQUIRED, $e->getErrorKey());
            self::assertSame('A password is required to join this game.', $e->getMessage());
        }
    }

    #[Test]
    public function itCreatesAndSavesANewAccountAndPlayerWithAHashedPassword(): void
    {
        $game = $this->game();

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createMock(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn(null);
        $players->expects(self::once())->method('save');

        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findByName')->willReturn(null);
        $accounts->expects(self::once())->method('save');

        $result = $this->service($games, $players, $accounts)->join('game-key', 'Bob', self::PASSWORD);

        self::assertTrue($result->isNew);
        self::assertSame('Bob', $result->player->getDisplayName());
        self::assertSame($game, $result->player->getGame());
        self::assertTrue($result->player->getAccount()->passwordMatches(self::PASSWORD));
        self::assertFalse($result->player->getAccount()->passwordMatches('not-the-password'));
    }

    #[Test]
    public function itAnnouncesANewPlayerInTheChat(): void
    {
        $game = $this->game();

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn(null);

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn(null);

        $posted = null;
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChatMessage $message) use (&$posted): void {
                $posted = $message;
            });

        $this->service($games, $players, $accounts, chat: $this->silentChat($messages))
            ->join('game-key', 'Bob', self::PASSWORD);

        self::assertInstanceOf(ChatMessage::class, $posted);
        self::assertSame(ChatMessageType::System, $posted->getType());
        self::assertSame('system.player_joined', $posted->getBodyKey());
        self::assertSame(['name' => 'Bob'], $posted->getBodyArgs());
        self::assertNull($posted->getSender());
    }

    #[Test]
    public function itStaysSilentWhenAPlayerWhoNeverLeftReconnects(): void
    {
        $game = $this->game();
        $account = AccountFactory::create('Alice', self::PASSWORD);
        $existing = new Player($game, $account);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createMock(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn($existing);
        $players->expects(self::never())->method('save');

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn($account);

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');

        $this->service($games, $players, $accounts, chat: $this->silentChat($messages))
            ->join('game-key', 'Alice', self::PASSWORD);
    }

    #[Test]
    public function itReinstatesAPlayerWhoHadLeft(): void
    {
        $game = $this->game();
        $account = AccountFactory::create('Carol', self::PASSWORD);
        $departed = new Player($game, $account)->markLeft(new \DateTimeImmutable());

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createMock(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn($departed);
        $players->expects(self::once())->method('save');

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn($account);

        $posted = null;
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChatMessage $message) use (&$posted): void {
                $posted = $message;
            });

        $result = $this->service($games, $players, $accounts, chat: $this->silentChat($messages))
            ->join('game-key', 'Carol', self::PASSWORD);

        self::assertSame($departed, $result->player);
        self::assertFalse($result->isNew);
        self::assertFalse($departed->hasLeft());
        self::assertInstanceOf(ChatMessage::class, $posted);
        self::assertSame('system.player_rejoined', $posted->getBodyKey());
    }

    #[Test]
    public function itSurfacesANameTakenErrorWhenTheNameRaceIsLost(): void
    {
        $game = $this->game();

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn(null);

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn(null);
        $accounts->method('save')->willThrowException(
            new UniqueConstraintViolationException(PdoException::new(new \PDOException('name race')), null),
        );

        try {
            $this->service($games, $players, $accounts)->join('game-key', 'Alice', self::PASSWORD);
            self::fail('Expected a FunctionalException for the lost name race.');
        } catch (FunctionalException $e) {
            self::assertSame(ErrorKey::JOIN_PASSWORD_REQUIRED, $e->getErrorKey());
        }
    }

    #[Test]
    public function itThrowsWhenTheGameKeyIsUnknown(): void
    {
        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn(null);

        self::expectException(EntityNotFoundException::class);
        $this->service(
            $games,
            $this->createStub(PlayerRepository::class),
            $this->createStub(AccountRepository::class),
        )->join('unknown-key', 'Alice', self::PASSWORD);
    }

    #[Test]
    public function itReturnsTheActiveRoundForAGame(): void
    {
        $game = $this->game();
        $active = new Round($game);
        $latest = new Round($game);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findActiveByGame')->willReturn($active);
        $rounds->method('findLatestByGame')->willReturn($latest);

        $service = new JoinService(
            $this->createStub(GameRepository::class),
            $rounds,
            $this->createStub(PlayerRepository::class),
            $this->createStub(AccountRepository::class),
            $this->transactionalEntityManager(),
            $this->silentRoster(),
            $this->silentChat(),
        );

        self::assertSame($active, $service->roundFor($game));
    }

    #[Test]
    public function itFallsBackToTheLatestRoundWhenNoneIsActive(): void
    {
        $game = $this->game();
        $latest = new Round($game);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findActiveByGame')->willReturn(null);
        $rounds->method('findLatestByGame')->willReturn($latest);

        $service = new JoinService(
            $this->createStub(GameRepository::class),
            $rounds,
            $this->createStub(PlayerRepository::class),
            $this->createStub(AccountRepository::class),
            $this->transactionalEntityManager(),
            $this->silentRoster(),
            $this->silentChat(),
        );

        self::assertSame($latest, $service->roundFor($game));
    }
}
