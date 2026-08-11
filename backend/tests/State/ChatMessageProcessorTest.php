<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\ValidatorInterface;
use App\Dto\ChatMessageInput;
use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Entity\Player;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Exception\IdentityRequiredException;
use App\Exception\RateLimitExceededException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameRepository;
use App\Repository\PlayerRepository;
use App\Service\ChatService;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Service\RateLimits;
use App\State\ChatMessageProcessor;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(ChatMessageProcessor::class)]
final class ChatMessageProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function theSenderIsTheTokenIdentity(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            static fn (ChatMessage $message): bool => $message->getSenderUuid() === $player->getUuid(),
        ));

        $processor = $this->processor($game, $player, $messages, $this->limiter(100));
        $resource = $processor->process($this->input('hello'), new Post(), ['gameKey' => $game->getUuid()]);

        self::assertSame($player->getUuid(), $resource->senderUuid);
        self::assertSame('hello', $resource->body);
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
            $this->processor($game, $player, $messages, $this->limiter(100))
                ->process($this->input('hello'), new Post(), ['gameKey' => $game->getUuid()]);
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

        $this->processor($game, null, $this->createStub(ChatMessageRepository::class), $this->limiter(100), withHeader: false)
            ->process($this->input('hello'), new Post(), ['gameKey' => $game->getUuid()]);
    }

    #[Test]
    public function itRejectsAnUnknownGame(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->processor($game, $player, $this->createStub(ChatMessageRepository::class), $this->limiter(100), games: $games)
            ->process($this->input('hello'), new Post(), ['gameKey' => $game->getUuid()]);
    }

    #[Test]
    public function anExhaustedChatLimiterRejectsTheMessage(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $limiter = $this->limiter(1);
        $limiter->chatSend($player->getUuid());

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');

        $this->expectException(RateLimitExceededException::class);

        $this->processor($game, $player, $messages, $limiter)
            ->process($this->input('hello'), new Post(), ['gameKey' => $game->getUuid()]);
    }

    private function input(string $body): ChatMessageInput
    {
        $input = new ChatMessageInput();
        $input->body = $body;

        return $input;
    }

    private function limiter(int $limit): RateLimits
    {
        return new RateLimits(
            new RateLimiterFactory(['id' => 'location', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage()),
            new RateLimiterFactory(['id' => 'chat', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage()),
        );
    }

    private function processor(
        Game $game,
        ?Player $player,
        ChatMessageRepository $messages,
        RateLimits $rateLimits,
        bool $withHeader = true,
        ?GameRepository $games = null,
    ): ChatMessageProcessor {
        $mercure = new MercureJwtService(self::SECRET);
        $hub = new FakeMercureHub();
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

        return new ChatMessageProcessor(
            $this->createStub(ValidatorInterface::class),
            $resolvedGames,
            new ChatService(
                $messages,
                $mercure,
                $hub,
                $this->createStub(ImageStorageInterface::class),
            ),
            new IdentityResolver($mercure, $players, $stack),
            $rateLimits,
        );
    }
}
