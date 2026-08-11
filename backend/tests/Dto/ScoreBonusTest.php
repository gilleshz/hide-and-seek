<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\ScoreBonus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScoreBonus::class)]
final class ScoreBonusTest extends TestCase
{
    private const int HOUR = 3600;

    #[Test]
    public function itTurnsFlatMinutesIntoSeconds(): void
    {
        self::assertSame(900, (new ScoreBonus(minutes: 15))->secondsFor(self::HOUR));
    }

    #[Test]
    public function itTakesThePercentageOffTheHidingTimeOnly(): void
    {
        $bonus = new ScoreBonus(minutes: 15, percent: 20);

        // 20% of the hour, not 20% of the hour plus the 15 minutes.
        self::assertSame(900 + 720, $bonus->secondsFor(self::HOUR));
    }

    #[Test]
    public function itAddsSeveralPercentagesRatherThanCompoundingThem(): void
    {
        $separately = (new ScoreBonus(percent: 10))->secondsFor(self::HOUR)
            + (new ScoreBonus(percent: 25))->secondsFor(self::HOUR);

        self::assertSame($separately, (new ScoreBonus(percent: 35))->secondsFor(self::HOUR));
    }

    #[Test]
    public function itReportsNoBonusWhenBothValuesAreZero(): void
    {
        self::assertTrue((new ScoreBonus())->isNone());
        self::assertFalse((new ScoreBonus(percent: 5))->isNone());
    }

    #[Test]
    public function itNeverTakesAPercentageOfANegativeHidingTime(): void
    {
        self::assertSame(0, (new ScoreBonus(percent: 50))->secondsFor(-120));
    }
}
