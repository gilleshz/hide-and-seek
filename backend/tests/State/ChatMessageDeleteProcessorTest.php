<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Entity\Player;
use App\Enum\ChatMessageType;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Exception\IdentityRequiredException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameRepository;
use App\Repository\PlayerRepository;
use App\Service\ChatService;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\State\ChatMessageDeleteProcessor;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(ChatMessageDeleteProcessor::class)]
final class ChatMessageDeleteProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function onlyTheTokenOwnerDeletesTheirOwnMessage(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $owner = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $message = new ChatMessage($game, $owner, ChatMessageType::Text, 'hello');

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->method('findOneByUuid')->willReturn($message);
        $messages->expects(self::once())->method('save')->with($message);

        $processor = $this->processor($game, $owner, $messages);
        $processor->process(null, new Post(), [
            'gameKey' => $game->getUuid(),
            'uuid' => $message->getUuid(),
        ]);

        self::assertTrue($message->isDeleted());
    }

    #[Test]
    public function itRefusesAnotherPlayersToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $owner = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $other = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $message = new ChatMessage($game, $owner, ChatMessageType::Text, 'hello');

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->method('findOneByUuid')->willReturn($message);
        $messages->expects(self::never())->method('save');

        try {
            $this->processor($game, $other, $messages)->process(null, new Post(), [
                'gameKey' => $game->getUuid(),
                'uuid' => $message->getUuid(),
            ]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('chat.not_your_message', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRefusesATokenPlayerFromAnotherGame(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $otherGame = new Game('Paris', GameSize::Small, Edition::Metric);
        $player = new Player($otherGame, AccountFactory::create('Eve', 'test-password'));

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');

        try {
            $this->processor($game, $player, $messages)->process(null, new Post(), [
                'gameKey' => $game->getUuid(),
                'uuid' => '00000000-0000-0000-0000-000000000000',
            ]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('chat.player_not_in_game', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);

        $this->expectException(IdentityRequiredException::class);

        $this->processor($game, null, $this->createStub(ChatMessageRepository::class), withHeader: false)
            ->process(null, new Post(), ['gameKey' => $game->getUuid(), 'uuid' => 'x']);
    }

    #[Test]
    public function itRejectsAnUnknownGame(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->processor($game, $player, $this->createStub(ChatMessageRepository::class), games: $games)
            ->process(null, new Post(), ['gameKey' => $game->getUuid(), 'uuid' => 'x']);
    }

    private function processor(
        Game $game,
        ?Player $player,
        ChatMessageRepository $messages,
        bool $withHeader = true,
        ?GameRepository $games = null,
    ): ChatMessageDeleteProcessor {
        $mercure = new MercureJwtService(self::SECRET);
        $stack = new RequestStack();
        $request = new Request();
        if ($withHeader && $player !== null) {
            $request->headers->set(IdentityResolver::HEADER, $mercure->issueSubscriberToken([], $player->getUuid()));
        }
        $stack->push($request);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);

        $defaultGames = $this->createStub(GameRepository::class);
        $defaultGames->method('findOneByUuid')->willReturn($game);
        $resolvedGames = $games ?? $defaultGames;

        return new ChatMessageDeleteProcessor(
            $resolvedGames,
            new ChatService(
                $messages,
                $mercure,
                new FakeMercureHub(),
                $this->createStub(ImageStorageInterface::class),
            ),
            new IdentityResolver($mercure, $players, $stack),
        );
    }
}
