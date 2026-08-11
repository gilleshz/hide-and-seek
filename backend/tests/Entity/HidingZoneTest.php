<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Game;
use App\Entity\HidingZone;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HidingZone::class)]
final class HidingZoneTest extends TestCase
{
    #[Test]
    public function itStoresAStationPointAndRadiusForARound(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $point = new Point(13.405, 52.52);

        $zone = new HidingZone($round, $point, 500.0);

        self::assertSame($round, $zone->getRound());
        self::assertSame(13.405, $zone->getStationPoint()->getLongitude());
        self::assertSame(52.52, $zone->getStationPoint()->getLatitude());
        self::assertSame(500.0, $zone->getRadiusMeters());
        self::assertSame(36, \strlen($zone->getUuid()));
        self::assertSame($zone->getCreatedAt(), $zone->getUpdatedAt());
    }

    #[Test]
    public function itAllowsUpdatingTheStationPointAndRadius(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $zone = new HidingZone($round, new Point(13.405, 52.52), 500.0);
        $updatedPoint = new Point(2.35, 48.85);

        $zone->setStationPoint($updatedPoint)->setRadiusMeters(1000.0);

        self::assertSame(2.35, $zone->getStationPoint()->getLongitude());
        self::assertSame(48.85, $zone->getStationPoint()->getLatitude());
        self::assertSame(1000.0, $zone->getRadiusMeters());
    }
}
