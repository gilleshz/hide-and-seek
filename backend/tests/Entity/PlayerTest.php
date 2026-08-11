<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Game;
use App\Entity\Player;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Player::class)]
final class PlayerTest extends TestCase
{
    #[Test]
    public function itLinksToItsGameAndAccountAndExposesTheAccountName(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Imperial);
        $account = AccountFactory::create('Alice');
        $player = new Player($game, $account);

        self::assertNull($player->getId());
        self::assertSame($game, $player->getGame());
        self::assertSame($account, $player->getAccount());
        self::assertSame('Alice', $player->getDisplayName());
        self::assertSame(36, \strlen($player->getUuid()));
        self::assertFalse($player->hasLeft());
        self::assertNull($player->getLeftAt());
    }

    #[Test]
    public function itMarksADepartureAndTakesThePlayerBack(): void
    {
        $player = new Player(new Game('Berlin', GameSize::Small, Edition::Imperial), AccountFactory::create('Alice'));
        $leftAt = new \DateTimeImmutable('2026-07-27 18:00:00');

        $player->markLeft($leftAt);

        self::assertTrue($player->hasLeft());
        self::assertSame($leftAt, $player->getLeftAt());

        $player->markReturned();

        self::assertFalse($player->hasLeft());
        self::assertNull($player->getLeftAt());
    }
}
