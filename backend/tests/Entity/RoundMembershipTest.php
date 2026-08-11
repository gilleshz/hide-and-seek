<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\Side;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoundMembership::class)]
final class RoundMembershipTest extends TestCase
{
    #[Test]
    public function itLinksARoundAndPlayerToASideAndAllowsSwitching(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $membership = new RoundMembership($round, $player, Side::Seeker);

        self::assertSame($round, $membership->getRound());
        self::assertSame($player, $membership->getPlayer());
        self::assertSame(Side::Seeker, $membership->getSide());
        self::assertSame(36, \strlen($membership->getUuid()));

        $membership->setSide(Side::Hider);

        self::assertSame(Side::Hider, $membership->getSide());
    }
}
