<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Game;
use App\Entity\Player;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Tests\Support\IdentityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Player::class)]
final class PlayerTest extends TestCase
{
    #[Test]
    public function itLinksToItsGameAndIdentityAndExposesTheIdentityName(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Imperial);
        $identity = IdentityFactory::create('Alice');
        $player = new Player($game, $identity);

        self::assertNull($player->getId());
        self::assertSame($game, $player->getGame());
        self::assertSame($identity, $player->getIdentity());
        self::assertSame('Alice', $player->getDisplayName());
        self::assertSame(36, \strlen($player->getUuid()));
        self::assertFalse($player->hasLeft());
        self::assertNull($player->getLeftAt());
    }

    #[Test]
    public function itMarksADepartureAndTakesThePlayerBack(): void
    {
        $player = new Player(new Game('Berlin', GameSize::Small, Edition::Imperial), IdentityFactory::create('Alice'));
        $leftAt = new \DateTimeImmutable('2026-07-27 18:00:00');

        $player->markLeft($leftAt);

        self::assertTrue($player->hasLeft());
        self::assertSame($leftAt, $player->getLeftAt());

        $player->markReturned();

        self::assertFalse($player->hasLeft());
        self::assertNull($player->getLeftAt());
    }
}
