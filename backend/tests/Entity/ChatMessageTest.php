<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Entity\Player;
use App\Enum\ChatMessageType;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatMessage::class)]
final class ChatMessageTest extends TestCase
{
    #[Test]
    public function itStoresATextMessageFromASender(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $message = new ChatMessage($game, $player, ChatMessageType::Text, 'Hello!');

        self::assertSame($game, $message->getGame());
        self::assertSame($player, $message->getSender());
        self::assertSame(ChatMessageType::Text, $message->getType());
        self::assertSame('Hello!', $message->getBody());
        self::assertSame(36, \strlen($message->getUuid()));
    }

    #[Test]
    public function itAnswersWhoSentItWithoutTheCallerWalkingTheRelation(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $message = new ChatMessage($game, $player, ChatMessageType::Text, 'Hello!');

        self::assertSame($player->getUuid(), $message->getSenderUuid());
        self::assertSame('Alice', $message->getSenderName());
    }

    #[Test]
    public function itStoresASystemMessageWithNoSender(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);

        $message = new ChatMessage($game, null, ChatMessageType::System, 'Hiding time: 12m 34s');

        self::assertNull($message->getSender());
        self::assertNull($message->getSenderUuid());
        self::assertNull($message->getSenderName());
        self::assertSame(ChatMessageType::System, $message->getType());
    }
}
