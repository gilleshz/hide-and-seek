<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Feature;
use App\Entity\Game;
use App\Enum\Edition;
use App\Enum\FeatureType;
use App\Enum\GameSize;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Feature::class)]
final class FeatureTest extends TestCase
{
    #[Test]
    public function itStoresFeatureData(): void
    {
        $game = new Game('Paris', GameSize::Medium, Edition::Metric);
        $point = new Point(2.3522, 48.8566);

        $feature = new Feature($game, FeatureType::Museum, 'Louvre Museum', $point);

        self::assertSame($game, $feature->getGame());
        self::assertSame(FeatureType::Museum, $feature->getFeatureType());
        self::assertSame('Louvre Museum', $feature->getName());
        self::assertSame(2.3522, $feature->getPoint()->getLongitude());
        self::assertSame(48.8566, $feature->getPoint()->getLatitude());
        self::assertSame(36, \strlen($feature->getUuid()));
    }

    #[Test]
    public function itAllowsANullName(): void
    {
        $game = new Game('Paris', GameSize::Small, Edition::Metric);
        $point = new Point(2.0, 48.0);

        $feature = new Feature($game, FeatureType::Hospital, null, $point);

        self::assertNull($feature->getName());
    }

    #[Test]
    public function itAcceptsAllFeatureTypes(): void
    {
        $game = new Game('Paris', GameSize::Large, Edition::Imperial);
        $point = new Point(0.0, 0.0);

        foreach (FeatureType::cases() as $type) {
            $feature = new Feature($game, $type, $type->value, $point);
            self::assertSame($type, $feature->getFeatureType());
            self::assertSame($type->value, $feature->getName());
        }
    }
}
