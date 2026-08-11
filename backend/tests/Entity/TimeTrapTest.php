<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Game;
use App\Entity\GameTransitStation;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\TimeTrap;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\TimeTrapStatus;
use App\Tests\Support\AccountFactory;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimeTrap::class)]
final class TimeTrapTest extends TestCase
{
    #[Test]
    public function itAdoptsTheStationItSnappedToAndStartsArmed(): void
    {
        $trap = self::trap(GameSize::Medium);

        self::assertSame(36, \strlen($trap->getUuid()));
        self::assertSame('Alexanderplatz', $trap->getStationName());
        self::assertSame(13.405, $trap->getPoint()->getLongitude());
        self::assertSame(52.52, $trap->getPoint()->getLatitude());
        self::assertSame(TimeTrapStatus::Armed, $trap->getStatus());
        self::assertNull($trap->getDetectedAt());
        self::assertNull($trap->getDetectedByPlayer());
        self::assertNull($trap->getFrozenValueSeconds());
        self::assertNull($trap->getRearmedAt());
        self::assertNull($trap->getAwardedSeconds());
        self::assertNull($trap->getId());
        self::assertSame('Alice', $trap->getPlacedByPlayer()->getDisplayName());
        self::assertSame('de:11000:900100003', $trap->getStation()->getStationId());
    }

    #[Test]
    public function itRecordsADetectionAndItsResolution(): void
    {
        $trap = self::trap(GameSize::Medium);
        $seeker = new Player($trap->getRound()->getGame(), AccountFactory::create('Bob', 'test-password'));
        $detectedAt = new \DateTimeImmutable('2026-07-31 12:00:00');

        $trap->setStatus(TimeTrapStatus::Pending)
            ->setDetectedAt($detectedAt)
            ->setDetectedByPlayer($seeker)
            ->setFrozenValueSeconds(360)
            ->setRearmedAt($detectedAt)
            ->setAwardedSeconds(360);

        self::assertSame(TimeTrapStatus::Pending, $trap->getStatus());
        self::assertSame($detectedAt, $trap->getDetectedAt());
        self::assertSame($seeker, $trap->getDetectedByPlayer());
        self::assertSame(360, $trap->getFrozenValueSeconds());
        self::assertSame($detectedAt, $trap->getRearmedAt());
        self::assertSame(360, $trap->getAwardedSeconds());
    }

    /**
     * @return array<string, array{size: GameSize, offset: string, expected: int}>
     */
    public static function values(): array
    {
        return [
            'small, first interval' => ['size' => GameSize::Small, 'offset' => '+14 minutes', 'expected' => 0],
            'small, at the boundary' => ['size' => GameSize::Small, 'offset' => '+15 minutes', 'expected' => 240],
            'small, third interval' => ['size' => GameSize::Small, 'offset' => '+47 minutes', 'expected' => 720],
            'medium, first interval' => ['size' => GameSize::Medium, 'offset' => '+29 minutes', 'expected' => 0],
            'medium, second interval' => ['size' => GameSize::Medium, 'offset' => '+31 minutes', 'expected' => 360],
            'large, first interval' => ['size' => GameSize::Large, 'offset' => '+59 minutes', 'expected' => 0],
            'large, second interval' => ['size' => GameSize::Large, 'offset' => '+61 minutes', 'expected' => 600],
        ];
    }

    #[DataProvider('values')]
    #[Test]
    public function itStepsTheValueAtIntervalBoundariesAndIsWorthNothingInTheFirst(
        GameSize $size,
        string $offset,
        int $expected,
    ): void {
        $trap = self::trap($size);

        self::assertSame($expected, $trap->valueSecondsAt(new \DateTimeImmutable($offset)));
    }

    #[Test]
    public function itNeverReportsANegativeValueForAnInstantBeforeThePlacement(): void
    {
        $trap = self::trap(GameSize::Medium);

        self::assertSame(0, $trap->valueSecondsAt(new \DateTimeImmutable('-1 hour')));
    }

    #[Test]
    public function itStopsAccruingOnceAPassIsDetectedAndReportsWhatASprungTrapPaid(): void
    {
        $trap = self::trap(GameSize::Medium);
        $longAfterTheDetection = new \DateTimeImmutable('+3 hours');

        self::assertSame(2160, $trap->effectiveValueSecondsAt($longAfterTheDetection));

        $trap->setStatus(TimeTrapStatus::Pending)->setFrozenValueSeconds(360);

        self::assertSame(360, $trap->effectiveValueSecondsAt($longAfterTheDetection));

        $trap->setStatus(TimeTrapStatus::Sprung)->setAwardedSeconds(360);

        self::assertSame(360, $trap->effectiveValueSecondsAt($longAfterTheDetection));
    }

    /** An armed trap is worth nothing once the round stops, so its chip must not keep climbing. */
    #[Test]
    public function itStopsAnArmedTrapAccruingWhenTheRoundStopped(): void
    {
        $trap = self::trap(GameSize::Medium);
        $round = $trap->getRound();
        $nextDay = new \DateTimeImmutable('+1 day');

        // A day of a medium game is 48 intervals of +6 min, exactly the runaway chip the round-stop freeze prevents.
        self::assertSame(48 * 6 * 60, $trap->effectiveValueSecondsAt($nextDay));

        $round->setSeekingEndedAt(new \DateTimeImmutable('+31 minutes'));

        self::assertSame(360, $trap->effectiveValueSecondsAt($nextDay));
        self::assertSame(0, $trap->effectiveValueSecondsAt(new \DateTimeImmutable('+10 minutes')));
    }

    private static function trap(GameSize $size): TimeTrap
    {
        $game = new Game('Berlin', $size, Edition::Metric);
        $round = new Round($game);
        $station = new GameTransitStation(
            $game,
            'de:11000:900100003',
            'Alexanderplatz',
            new Point(13.405, 52.52),
            ['U2'],
        );

        return new TimeTrap($round, new Player($game, AccountFactory::create('Alice', 'test-password')), $station);
    }
}
