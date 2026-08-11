<?php

declare(strict_types=1);

namespace App\Tests;

use App\GeoDistance;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GeoDistance::class)]
final class GeoDistanceTest extends TestCase
{
    #[Test]
    public function itReturnsZeroForIdenticalPoints(): void
    {
        $point = new Point(13.405, 52.52);

        self::assertSame(0.0, GeoDistance::metersBetween($point, $point));
    }

    #[Test]
    public function oneDegreeOfLatitudeIsRoughlyOneHundredElevenKilometres(): void
    {
        $distance = GeoDistance::metersBetween(new Point(0.0, 0.0), new Point(0.0, 1.0));

        self::assertEqualsWithDelta(111195.0, $distance, 5.0);
    }

    #[Test]
    public function itIsSymmetric(): void
    {
        $a = new Point(13.405, 52.52);
        $b = new Point(2.35, 48.85);

        self::assertEqualsWithDelta(
            GeoDistance::metersBetween($a, $b),
            GeoDistance::metersBetween($b, $a),
            0.0001,
        );
    }

    #[Test]
    public function itMatchesTheKnownBerlinParisGreatCircleDistance(): void
    {
        $berlin = new Point(13.405, 52.52);
        $paris = new Point(2.3522, 48.8566);

        self::assertEqualsWithDelta(877_460.0, GeoDistance::metersBetween($berlin, $paris), 2_000.0);
    }
}
