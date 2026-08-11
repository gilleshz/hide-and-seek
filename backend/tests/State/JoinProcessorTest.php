<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\ValidatorInterface;
use App\Dto\JoinInput;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
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
use App\State\JoinProcessor;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JoinProcessor::class)]
final class JoinProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';
    private const string PASSWORD = 'correct-password';

    #[Test]
    public function theJoinResponseNeverExposesACredential(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $game->setJoinCode('ABCDEF');

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn(null);

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn(null);

        $processor = $this->processor($game, $round, $players, $accounts);
        $input = new JoinInput();
        $input->name = 'Alice';
        $input->password = self::PASSWORD;

        $resource = $processor->process($input, new Post(), ['gameKey' => 'ABCDEF']);

        self::assertSame('Alice', $resource->displayName);
        self::assertSame($round->getUuid(), $resource->roundUuid);
        self::assertObjectNotHasProperty('joinSecret', $resource);
        self::assertObjectNotHasProperty('password', $resource);
        self::assertObjectNotHasProperty('passwordHash', $resource);
    }

    #[Test]
    public function aRejoinWithThePasswordReturnsTheSamePlayer(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $account = AccountFactory::create('Alice', self::PASSWORD);
        $player = new Player($game, $account);
        $game->setJoinCode('ABCDEF');

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn($player);

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn($account);

        $processor = $this->processor($game, $round, $players, $accounts);
        $input = new JoinInput();
        $input->name = 'Alice';
        $input->password = self::PASSWORD;

        $resource = $processor->process($input, new Post(), ['gameKey' => 'ABCDEF']);

        self::assertSame($player->getUuid(), $resource->playerUuid);
        self::assertObjectNotHasProperty('joinSecret', $resource);
    }

    #[Test]
    public function aRejoinWithoutThePasswordIsRefused(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $account = AccountFactory::create('Alice', self::PASSWORD);
        $player = new Player($game, $account);
        $game->setJoinCode('ABCDEF');

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn($player);

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn($account);

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('enter its password');

        $processor = $this->processor($game, $round, $players, $accounts);
        $input = new JoinInput();
        $input->name = 'Alice';

        $processor->process($input, new Post(), ['gameKey' => 'ABCDEF']);
    }

    #[Test]
    public function aRejoinWithAWrongPasswordIsRefused(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $account = AccountFactory::create('Alice', self::PASSWORD);
        $player = new Player($game, $account);
        $game->setJoinCode('ABCDEF');

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndAccount')->willReturn($player);

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn($account);

        try {
            $processor = $this->processor($game, $round, $players, $accounts);
            $input = new JoinInput();
            $input->name = 'Alice';
            $input->password = 'wrong-password';

            $processor->process($input, new Post(), ['gameKey' => 'ABCDEF']);
            self::fail('Expected a FunctionalException for a wrong password.');
        } catch (FunctionalException $e) {
            self::assertSame(ErrorKey::JOIN_PASSWORD_INVALID, $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsAnUnknownGame(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn(null);
        $games->method('findOneBy')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->processor(
            $game,
            $round,
            $this->createStub(PlayerRepository::class),
            $this->createStub(AccountRepository::class),
            games: $games,
        )->process(new JoinInput(), new Post(), ['gameKey' => 'UNKNOWN']);
    }

    private function processor(
        Game $game,
        Round $round,
        PlayerRepository $players,
        AccountRepository $accounts,
        ?GameRepository $games = null,
    ): JoinProcessor {
        $mercure = new MercureJwtService(self::SECRET);
        $hub = new FakeMercureHub();
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findActiveByGame')->willReturn($round);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findByRound')->willReturn([]);

        $defaultGames = $this->createStub(GameRepository::class);
        $defaultGames->method('findOneByUuid')->willReturn($game);
        $defaultGames->method('findOneBy')->willReturn($game);
        $resolvedGames = $games ?? $defaultGames;

        $chat = new ChatService(
            $this->createStub(ChatMessageRepository::class),
            $mercure,
            $hub,
            $this->createStub(ImageStorageInterface::class),
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $joinService = new JoinService(
            $resolvedGames,
            $rounds,
            $players,
            $accounts,
            $entityManager,
            new RosterNotifier($hub, $mercure, new RosterService($players, $rounds, $memberships)),
            $chat,
        );

        return new JoinProcessor($this->createStub(ValidatorInterface::class), $joinService, $mercure);
    }
}
