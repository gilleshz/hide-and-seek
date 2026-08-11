<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\SeekerCandidateMarker;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Tests\Support\AccountFactory;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeekerCandidateMarker::class)]
final class SeekerCandidateMarkerTest extends TestCase
{
    #[Test]
    public function itStoresARoundPlayerAndPoint(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $point = new Point(13.405, 52.52);

        $marker = new SeekerCandidateMarker($round, $player, $point);

        self::assertSame($round, $marker->getRound());
        self::assertSame($player, $marker->getPlayer());
        self::assertSame(13.405, $marker->getPoint()->getLongitude());
        self::assertSame(52.52, $marker->getPoint()->getLatitude());
        self::assertSame(36, \strlen($marker->getUuid()));
        self::assertNull($marker->getId());
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $marker->getCreatedAt());
    }
}
