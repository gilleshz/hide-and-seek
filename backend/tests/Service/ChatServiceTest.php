<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Entity\Player;
use App\Enum\ChatMessageType;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Exception\FunctionalException;
use App\Repository\ChatMessageRepository;
use App\Service\ChatService;
use App\Service\MercureJwtService;
use App\Storage\ImageStorageInterface;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(ChatService::class)]
final class ChatServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    private function serviceWithSilentHub(ChatMessageRepository $messages): ChatService
    {
        return $this->service($messages, $this->createStub(HubInterface::class));
    }

    private function service(ChatMessageRepository $messages, HubInterface $hub): ChatService
    {
        return new ChatService(
            $messages,
            new MercureJwtService(self::SECRET),
            $hub,
            $this->createStub(ImageStorageInterface::class),
        );
    }

    #[Test]
    public function itPostsATextMessageFromASenderAndPublishesIt(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save');

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->with(self::callback(
            function (Update $update) use ($game, $player): bool {
                self::assertSame(["game/{$game->getUuid()}/chat"], $update->getTopics());
                self::assertStringContainsString($player->getUuid(), $update->getData());
                self::assertStringContainsString('"senderName":"Alice"', $update->getData());
                self::assertStringContainsString('Hello!', $update->getData());

                return true;
            },
        ));

        $message = $this->service($messages, $hub)
            ->postText($game, $player, 'Hello!');

        self::assertSame(ChatMessageType::Text, $message->getType());
        self::assertSame($player, $message->getSender());
    }

    #[Test]
    public function itPostsATextMessageWithAReplyToUuidAndPublishesIt(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $referencedMessage = new ChatMessage($game, $player, ChatMessageType::Text, 'Original message');

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('findOneByUuid')
            ->with('msg-42')
            ->willReturn($referencedMessage);
        $messages->expects(self::once())->method('save');

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->with(self::callback(
            function (Update $update) use ($game): bool {
                self::assertSame(["game/{$game->getUuid()}/chat"], $update->getTopics());
                self::assertStringContainsString('Hello!', $update->getData());
                self::assertStringContainsString('"replyToUuid":"msg-42"', $update->getData());
                return true;
            },
        ));

        $message = $this->service($messages, $hub)
            ->postText($game, $player, 'Hello!', 'msg-42');

        self::assertSame(ChatMessageType::Text, $message->getType());
        self::assertSame($player, $message->getSender());
        self::assertSame('msg-42', $message->getReplyToUuid());
    }

    #[Test]
    public function itPostsATextMessageWithoutReplyToUuidWhenOmitted(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save');

        $message = $this->serviceWithSilentHub($messages)
            ->postText($game, $player, 'Hello!');

        self::assertSame(ChatMessageType::Text, $message->getType());
        self::assertNull($message->getReplyToUuid());
    }

    #[Test]
    public function itPostsAnImageMessageCarryingBothACaptionAndAReplyTarget(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $referenced = new ChatMessage($game, $player, ChatMessageType::Text, 'Original message');

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('findOneByUuid')
            ->with($referenced->getUuid())
            ->willReturn($referenced);
        $messages->expects(self::once())->method('save');

        $message = $this->serviceWithSilentHub($messages)
            ->postImage($game, $player, 'photo.jpg', 'Look at this', $referenced->getUuid());

        self::assertSame(ChatMessageType::Image, $message->getType());
        self::assertSame('photo.jpg', $message->getImageRef());
        self::assertSame('Look at this', $message->getBody());
        self::assertSame($referenced->getUuid(), $message->getReplyToUuid());
    }

    #[Test]
    public function itDropsAReplyTargetThatBelongsToAnotherGame(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $otherGame = new Game('Paris', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $foreign = new ChatMessage($otherGame, null, ChatMessageType::Text, 'Elsewhere');

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('findOneByUuid')->willReturn($foreign);
        $messages->expects(self::once())->method('save');

        $message = $this->serviceWithSilentHub($messages)
            ->postImage($game, $player, 'photo.jpg', null, $foreign->getUuid());

        self::assertNull($message->getReplyToUuid());
    }

    #[Test]
    public function itPostsASystemMessageWithNoSenderAndPublishesIt(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save');

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->with(self::callback(
            function (Update $update) use ($game): bool {
                self::assertSame(["game/{$game->getUuid()}/chat"], $update->getTopics());
                self::assertStringContainsString('Hiding time: 12m 34s', $update->getData());
                self::assertStringContainsString('"senderName":null', $update->getData());

                return true;
            },
        ));

        $message = $this->service($messages, $hub)
            ->postSystem($game, 'Hiding time: 12m 34s');

        self::assertSame(ChatMessageType::System, $message->getType());
        self::assertNull($message->getSender());
    }

    #[Test]
    public function itPostsAQuestionMessageCarryingTheQuestionUuid(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save');

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->with(self::callback(
            static function (Update $update): bool {
                self::assertStringContainsString('question-uuid-1', $update->getData());
                self::assertStringContainsString('"senderName":"Bob"', $update->getData());
                self::assertStringContainsString('"messageType":"question"', $update->getData());

                return true;
            },
        ));

        $message = $this->service($messages, $hub)
            ->postQuestion($game, $seeker, 'Are you within 500 m of me?', 'question-uuid-1');

        self::assertSame(ChatMessageType::Question, $message->getType());
        self::assertSame($seeker, $message->getSender());
        self::assertSame('question-uuid-1', $message->getQuestionUuid());
        self::assertNull($message->getReplyToUuid());
    }

    #[Test]
    public function itPostsAQuestionInfoMessageCarryingTheQuestionUuid(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save');

        $message = $this->serviceWithSilentHub($messages)
            ->postQuestionInfo($game, $seeker, "I'm starting a 1 km thermometer...", 'question-uuid-1');

        self::assertSame(ChatMessageType::QuestionInfo, $message->getType());
        self::assertSame($seeker, $message->getSender());
        self::assertSame('question-uuid-1', $message->getQuestionUuid());
    }

    #[Test]
    public function itPostsAnAnswerReplyingToTheAskMessage(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $askMessage = new ChatMessage($game, $seeker, ChatMessageType::Question, 'Are you within 500 m of me?');
        $askMessage->setQuestionUuid('question-uuid-1');

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('findOneByQuestionUuidAndType')
            ->with('question-uuid-1', ChatMessageType::Question)
            ->willReturn($askMessage);
        $messages->expects(self::once())->method('save');

        $message = $this->serviceWithSilentHub($messages)
            ->postAnswer($game, $hider, 'Yes, within range', 'question-uuid-1');

        self::assertSame(ChatMessageType::Answer, $message->getType());
        self::assertSame($hider, $message->getSender());
        self::assertSame('question-uuid-1', $message->getQuestionUuid());
        self::assertSame($askMessage->getUuid(), $message->getReplyToUuid());
    }

    #[Test]
    public function itPostsAnAnswerWithoutAReplyWhenTheAskMessageIsMissing(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->method('findOneByQuestionUuidAndType')->willReturn(null);
        $messages->expects(self::once())->method('save');

        $message = $this->serviceWithSilentHub($messages)
            ->postAnswer($game, $hider, 'Hotter', 'question-uuid-1');

        self::assertSame(ChatMessageType::Answer, $message->getType());
        self::assertNull($message->getReplyToUuid());
    }

    #[Test]
    public function itPostsACancellationNoticeReplyingToTheLatestQuestionMessage(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $latest = new ChatMessage($game, $seeker, ChatMessageType::Question, 'Are you within 500 m of me?');
        $latest->setQuestionUuid('question-uuid-1');

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('findLatestByQuestionUuid')
            ->with('question-uuid-1')
            ->willReturn($latest);
        $messages->expects(self::once())->method('save');

        $canceller = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $message = $this->serviceWithSilentHub($messages)
            ->postQuestionCancelled($game, $canceller, 'Question cancelled.', 'question-uuid-1');

        self::assertSame(ChatMessageType::Text, $message->getType());
        self::assertSame($canceller, $message->getSender());
        self::assertSame('question-uuid-1', $message->getQuestionUuid());
        self::assertSame($latest->getUuid(), $message->getReplyToUuid());
    }

    #[Test]
    public function itScrubsADeletedMessageAndPublishesTheRetraction(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $sender = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $target = new ChatMessage($game, $sender, ChatMessageType::Text, 'Regretted');

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->method('findOneByUuid')->willReturn($target);
        $messages->expects(self::once())->method('save');

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->with(self::callback(
            static function (Update $update): bool {
                self::assertStringContainsString('"type":"chat-deleted"', $update->getData());
                self::assertStringNotContainsString('Regretted', $update->getData());

                return true;
            },
        ));

        $deleted = $this->service($messages, $hub)->deleteMessage($game, $sender, $target->getUuid());

        self::assertTrue($deleted->isDeleted());
        self::assertNull($deleted->getBody());
        self::assertSame($sender, $deleted->getSender());
    }

    #[Test]
    public function itRefusesToDeleteAMessageSentBySomeoneElse(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $sender = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $other = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $target = new ChatMessage($game, $sender, ChatMessageType::Text, 'Mine');

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->method('findOneByUuid')->willReturn($target);
        $messages->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);
        $this->serviceWithSilentHub($messages)->deleteMessage($game, $other, $target->getUuid());
    }

    #[Test]
    public function itRefusesToDeleteAQuestionMessage(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $target = new ChatMessage($game, $seeker, ChatMessageType::Question, 'Are you within 500 m of me?');

        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->method('findOneByUuid')->willReturn($target);
        $messages->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);
        $this->serviceWithSilentHub($messages)->deleteMessage($game, $seeker, $target->getUuid());
    }

    #[Test]
    public function itDeletesTheStoredImageOfADeletedImageMessage(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $sender = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $target = new ChatMessage($game, $sender, ChatMessageType::Image, null);
        $target->setImageRef('photo.jpg');

        $messages = $this->createStub(ChatMessageRepository::class);
        $messages->method('findOneByUuid')->willReturn($target);

        $storage = $this->createMock(ImageStorageInterface::class);
        $storage->expects(self::once())->method('delete')->with($game->getUuid(), 'photo.jpg');

        $service = new ChatService(
            $messages,
            new MercureJwtService(self::SECRET),
            $this->createStub(HubInterface::class),
            $storage,
        );
        $deleted = $service->deleteMessage($game, $sender, $target->getUuid());

        self::assertNull($deleted->getImageRef());
    }
}
