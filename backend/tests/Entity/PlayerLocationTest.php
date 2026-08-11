<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Game;
use App\Entity\Player;
use App\Entity\PlayerLocation;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Tests\Support\AccountFactory;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlayerLocation::class)]
final class PlayerLocationTest extends TestCase
{
    #[Test]
    public function itStoresAPointForARoundAndPlayer(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $point = new Point(13.405, 52.52);

        $location = new PlayerLocation($round, $player, $point);

        self::assertSame($round, $location->getRound());
        self::assertSame($player, $location->getPlayer());
        self::assertSame(13.405, $location->getPoint()->getLongitude());
        self::assertSame(52.52, $location->getPoint()->getLatitude());
        self::assertSame(36, \strlen($location->getUuid()));
        self::assertNull($location->getAltitude());
    }

    #[Test]
    public function itStoresAnOptionalAltitude(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $point = new Point(13.405, 52.52);

        $location = new PlayerLocation($round, $player, $point, -12.5);

        self::assertSame(-12.5, $location->getAltitude());

        $location->setAltitude(34.0);
        self::assertSame(34.0, $location->getAltitude());
    }
}
