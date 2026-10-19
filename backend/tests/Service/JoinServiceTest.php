<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Entity\Identity;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\ChatMessageType;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\ErrorKey;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameRepository;
use App\Repository\IdentityRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\ChatService;
use App\Service\JoinService;
use App\Service\MercureJwtService;
use App\Service\RosterNotifier;
use App\Service\RosterService;
use App\Storage\ImageStorageInterface;
use App\Tests\Support\IdentityFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(JoinService::class)]
final class JoinServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';
    private const string DEVICE_KEY = 'test-device-key';
    private const string OTHER_DEVICE_KEY = 'other-device-key';

    private function silentRoster(): RosterNotifier
    {
        return new RosterNotifier(
            $this->createStub(HubInterface::class),
            new MercureJwtService(self::SECRET, 'https://jetlag.test'),
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
            new MercureJwtService(self::SECRET, 'https://jetlag.test'),
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
        IdentityRepository $identities,
        ?RosterNotifier $roster = null,
        ?ChatService $chat = null,
    ): JoinService {
        return new JoinService(
            $games,
            $this->createStub(RoundRepository::class),
            $players,
            $identities,
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
    public function itReconnectsToTheExistingPlayerForTheSameIdentityAndDevice(): void
    {
        $game = $this->game();
        $identity = IdentityFactory::create('Alice', self::DEVICE_KEY);
        $existing = new Player($game, $identity);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createMock(PlayerRepository::class);
        $players->method('findOneByGameAndIdentity')->willReturn($existing);
        $players->expects(self::never())->method('save');

        $identities = $this->createMock(IdentityRepository::class);
        $identities->expects(self::once())->method('lockName')->with('Alice');
        $identities->method('findByName')->willReturn($identity);

        $result = $this->service($games, $players, $identities)->join('game-key', 'Alice', self::DEVICE_KEY);

        self::assertSame($existing, $result->player);
        self::assertFalse($result->isNew);
    }

    #[Test]
    public function itRejectsADifferentDeviceWhenNoResetWasRequested(): void
    {
        $game = $this->game();
        $identity = IdentityFactory::create('Alice', self::DEVICE_KEY);
        $existing = new Player($game, $identity);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndIdentity')->willReturn($existing);

        $identities = $this->createStub(IdentityRepository::class);
        $identities->method('findByName')->willReturn($identity);

        try {
            $this->service($games, $players, $identities)->join('game-key', 'Alice', self::OTHER_DEVICE_KEY);
            self::fail('Expected a FunctionalException for a second device.');
        } catch (FunctionalException $e) {
            self::assertSame(ErrorKey::JOIN_DEVICE_MISMATCH, $e->getErrorKey());
            self::assertStringContainsString('another device', $e->getMessage());
        }
    }

    #[Test]
    public function itAcceptsANewDeviceWhenTheResetWasRequested(): void
    {
        $game = $this->game();
        $identity = IdentityFactory::create('Alice', self::DEVICE_KEY)->requestCredentialReset();
        $existing = new Player($game, $identity);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndIdentity')->willReturn($existing);

        $identities = $this->createMock(IdentityRepository::class);
        $identities->expects(self::once())->method('lockName')->with('Alice');
        $identities->method('findByName')->willReturn($identity);
        $identities->expects(self::once())->method('save')->with($identity);

        $result = $this->service($games, $players, $identities)->join(
            'game-key',
            'Alice',
            self::OTHER_DEVICE_KEY,
        );

        self::assertSame($existing, $result->player);
        self::assertTrue($identity->credentialMatches(self::OTHER_DEVICE_KEY));
        self::assertFalse($identity->credentialMatches(self::DEVICE_KEY));
        self::assertFalse($identity->isCredentialResetRequested());
    }

    #[Test]
    public function itKeepsTheResetFlagWhenTheSameDeviceJoins(): void
    {
        $game = $this->game();
        $identity = IdentityFactory::create('Alice', self::DEVICE_KEY)->requestCredentialReset();
        $existing = new Player($game, $identity);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndIdentity')->willReturn($existing);

        $identities = $this->createMock(IdentityRepository::class);
        $identities->method('findByName')->willReturn($identity);
        $identities->expects(self::never())->method('save');

        $this->service($games, $players, $identities)->join('game-key', 'Alice', self::DEVICE_KEY);

        self::assertTrue($identity->isCredentialResetRequested());
    }

    #[Test]
    public function itCreatesANewIdentityAndPlayerWithAHashedDeviceKey(): void
    {
        $game = $this->game();

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createMock(PlayerRepository::class);
        $players->method('findOneByGameAndIdentity')->willReturn(null);
        $players->expects(self::once())->method('save');

        $created = null;
        $identities = $this->createMock(IdentityRepository::class);
        $identities->expects(self::once())->method('lockName')->with('Bob');
        $identities->method('findByName')->willReturn(null);
        $identities->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (Identity $identity) use (&$created): void {
                $created = $identity;
            });

        $result = $this->service($games, $players, $identities)->join('game-key', 'Bob', self::DEVICE_KEY);

        self::assertTrue($result->isNew);
        self::assertSame('Bob', $result->player->getDisplayName());
        self::assertSame($game, $result->player->getGame());
        self::assertInstanceOf(Identity::class, $created);
        self::assertTrue($created->credentialMatches(self::DEVICE_KEY));
        self::assertFalse($created->credentialMatches('not-the-device-key'));
        self::assertFalse($created->isCredentialResetRequested());
    }

    #[Test]
    public function itAnnouncesANewPlayerInTheChat(): void
    {
        $game = $this->game();

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndIdentity')->willReturn(null);

        $identities = $this->createStub(IdentityRepository::class);
        $identities->method('findByName')->willReturn(null);

        $posted = null;
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChatMessage $message) use (&$posted): void {
                $posted = $message;
            });

        $this->service($games, $players, $identities, chat: $this->silentChat($messages))
            ->join('game-key', 'Bob', self::DEVICE_KEY);

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
        $identity = IdentityFactory::create('Alice', self::DEVICE_KEY);
        $existing = new Player($game, $identity);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createMock(PlayerRepository::class);
        $players->method('findOneByGameAndIdentity')->willReturn($existing);
        $players->expects(self::never())->method('save');

        $identities = $this->createStub(IdentityRepository::class);
        $identities->method('findByName')->willReturn($identity);

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');

        $this->service($games, $players, $identities, chat: $this->silentChat($messages))
            ->join('game-key', 'Alice', self::DEVICE_KEY);
    }

    #[Test]
    public function itReinstatesAPlayerWhoHadLeft(): void
    {
        $game = $this->game();
        $identity = IdentityFactory::create('Carol', self::DEVICE_KEY);
        $departed = new Player($game, $identity)->markLeft(new \DateTimeImmutable());

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $players = $this->createMock(PlayerRepository::class);
        $players->method('findOneByGameAndIdentity')->willReturn($departed);
        $players->expects(self::once())->method('save');

        $identities = $this->createStub(IdentityRepository::class);
        $identities->method('findByName')->willReturn($identity);

        $posted = null;
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChatMessage $message) use (&$posted): void {
                $posted = $message;
            });

        $result = $this->service($games, $players, $identities, chat: $this->silentChat($messages))
            ->join('game-key', 'Carol', self::DEVICE_KEY);

        self::assertSame($departed, $result->player);
        self::assertFalse($result->isNew);
        self::assertFalse($departed->hasLeft());
        self::assertInstanceOf(ChatMessage::class, $posted);
        self::assertSame('system.player_rejoined', $posted->getBodyKey());
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
            $this->createStub(IdentityRepository::class),
        )->join('unknown-key', 'Alice', self::DEVICE_KEY);
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
            $this->createStub(IdentityRepository::class),
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
            $this->createStub(IdentityRepository::class),
            $this->transactionalEntityManager(),
            $this->silentRoster(),
            $this->silentChat(),
        );

        self::assertSame($latest, $service->roundFor($game));
    }
}
