<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use App\Service\RoundClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoundClock::class)]
final class RoundClockTest extends TestCase
{
    #[Test]
    public function anElapsedHidingPeriodCountsAsSeekingBeforeATickFlipsTheStatus(): void
    {
        $round = $this->round(RoundStatus::Hiding, '-1 second');

        self::assertTrue(new RoundClock()->isSeeking($round));
    }

    #[Test]
    public function aRunningHidingPeriodIsNotSeeking(): void
    {
        $round = $this->round(RoundStatus::Hiding, '+10 minutes');

        self::assertFalse(new RoundClock()->isSeeking($round));
    }

    #[Test]
    public function aLobbyOrEndedRoundIsNeverSeeking(): void
    {
        $clock = new RoundClock();

        self::assertFalse($clock->isSeeking($this->round(RoundStatus::Lobby, '-1 hour')));
        self::assertFalse($clock->isSeeking($this->round(RoundStatus::Ended, '-1 hour')));
    }

    #[Test]
    public function aMoveWindowIsOpenOnlyWhileItsHidingPeriodStillRuns(): void
    {
        $clock = new RoundClock();

        $open = $this->round(RoundStatus::Hiding, '+5 minutes')->setInMovePeriod(true);
        self::assertTrue($clock->isMoveWindowOpen($open));

        $elapsed = $this->round(RoundStatus::Hiding, '-1 second')->setInMovePeriod(true);
        self::assertFalse($clock->isMoveWindowOpen($elapsed));

        $ordinary = $this->round(RoundStatus::Hiding, '+5 minutes');
        self::assertFalse($clock->isMoveWindowOpen($ordinary));
    }

    private function round(RoundStatus $status, string $endsAt): Round
    {
        $round = new Round(new Game('Berlin', GameSize::Medium, Edition::Metric));

        return $round->setStatus($status)->setHidingPeriodEndsAt(new \DateTimeImmutable($endsAt));
    }
}
