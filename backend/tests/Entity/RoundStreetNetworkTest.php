<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Game;
use App\Entity\Round;
use App\Entity\RoundStreetNetwork;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\StreetNetworkStatus;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoundStreetNetwork::class)]
final class RoundStreetNetworkTest extends TestCase
{
    #[Test]
    public function itStartsPendingForTheCentreAndRadiusItWasEnqueuedFor(): void
    {
        $round = new Round(new Game('Berlin', GameSize::Medium, Edition::Metric));

        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);

        self::assertSame($round, $network->getRound());
        self::assertSame(13.405, $network->getCenter()->getLongitude());
        self::assertSame(52.52, $network->getCenter()->getLatitude());
        self::assertSame(500.0, $network->getRadiusMeters());
        self::assertSame(StreetNetworkStatus::Pending, $network->getStatus());
        self::assertNull($network->getPayload());
        self::assertSame(0, $network->getWayCount());
        self::assertSame(0, $network->getAttempts());
        self::assertNull($network->getFetchedAt());
        self::assertNull($network->getId());
        self::assertSame(36, \strlen($network->getUuid()));
        self::assertSame($network->getCreatedAt(), $network->getUpdatedAt());
    }

    #[Test]
    public function itHoldsTheTrimmedWayListOnceWarmed(): void
    {
        $round = new Round(new Game('Berlin', GameSize::Medium, Edition::Metric));
        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);
        $fetchedAt = new \DateTimeImmutable('2026-08-01 12:00:00');
        $payload = [[
            'class' => 'residential',
            'coordinates' => [[13.40512, 52.52001], [13.40598, 52.52044]],
            'junctionIndices' => [0],
        ]];

        $network
            ->setCenter(new Point(2.35, 48.85))
            ->setRadiusMeters(750.0)
            ->setPayload($payload)
            ->setWayCount(1)
            ->setAttempts(2)
            ->setFetchedAt($fetchedAt)
            ->setStatus(StreetNetworkStatus::Ready);

        self::assertSame(2.35, $network->getCenter()->getLongitude());
        self::assertSame(750.0, $network->getRadiusMeters());
        self::assertSame($payload, $network->getPayload());
        self::assertSame(1, $network->getWayCount());
        self::assertSame(2, $network->getAttempts());
        self::assertSame($fetchedAt, $network->getFetchedAt());
        self::assertSame(StreetNetworkStatus::Ready, $network->getStatus());
        self::assertGreaterThanOrEqual($network->getCreatedAt(), $network->getUpdatedAt());
    }
}
