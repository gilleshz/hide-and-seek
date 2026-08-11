<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Dto\ScoreBonus;
use App\Entity\Game;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Round::class)]
final class RoundTest extends TestCase
{
    #[Test]
    public function itStartsInLobbyAndTransitions(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);

        self::assertSame($game, $round->getGame());
        self::assertSame(RoundStatus::Lobby, $round->getStatus());

        $round->setStatus(RoundStatus::Hiding);

        self::assertSame(RoundStatus::Hiding, $round->getStatus());
    }

    #[Test]
    public function itTracksTheTimerTimestamps(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);

        self::assertNull($round->getHidingPeriodStartedAt());
        self::assertNull($round->getHidingPeriodEndsAt());
        self::assertNull($round->getSeekingEndedAt());

        $startedAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');
        $endsAt = new \DateTimeImmutable('2026-01-01T11:00:00Z');
        $endedAt = new \DateTimeImmutable('2026-01-01T12:00:00Z');

        $round
            ->setHidingPeriodStartedAt($startedAt)
            ->setHidingPeriodEndsAt($endsAt)
            ->setSeekingEndedAt($endedAt);

        self::assertSame($startedAt, $round->getHidingPeriodStartedAt());
        self::assertSame($endsAt, $round->getHidingPeriodEndsAt());
        self::assertSame($endedAt, $round->getSeekingEndedAt());
    }

    #[Test]
    public function itIsNeitherCaughtNorAttributedUntilTheHidersDeclareAStop(): void
    {
        $round = new Round(new Game('Berlin', GameSize::Medium, Edition::Metric));

        self::assertFalse($round->isCaught());
        self::assertNull($round->getHiderNames());

        $round->setCaught(true)->setHiderNames(['Alice', 'Bob']);

        self::assertTrue($round->isCaught());
        self::assertSame(['Alice', 'Bob'], $round->getHiderNames());
    }

    #[Test]
    public function itDerivesTheHidingTimeAndScoreOnlyOnceTheRoundHasEnded(): void
    {
        $round = new Round(new Game('Berlin', GameSize::Medium, Edition::Metric));
        $round
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('2026-01-01T11:00:00Z'))
            ->setSeekingEndedAt(new \DateTimeImmutable('2026-01-01T12:00:00Z'))
            ->setBankedSeekingSeconds(600)
            ->setBonus(new ScoreBonus(minutes: 15, percent: 20));

        self::assertNull($round->getHidingTimeSeconds());
        self::assertNull($round->getScoreSeconds());

        $round->setStatus(RoundStatus::Ended);

        self::assertSame(4200, $round->getHidingTimeSeconds());
        self::assertSame(4200 + 900 + 840, $round->getScoreSeconds());
    }

    /** A percentage bonus is a share of the raw hiding time, so a sprung trap must not compound it. */
    #[Test]
    public function itAddsASprungTrapOutsideThePercentageBase(): void
    {
        $round = new Round(new Game('Berlin', GameSize::Medium, Edition::Metric));
        $round
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('2026-01-01T11:00:00Z'))
            ->setSeekingEndedAt(new \DateTimeImmutable('2026-01-01T12:00:00Z'))
            ->setBonus(new ScoreBonus(minutes: 0, percent: 20))
            ->setStatus(RoundStatus::Ended);

        self::assertSame(0, $round->getTrapBonusSeconds());
        self::assertSame(3600 + 720, $round->getScoreSeconds());

        $round->addTrapBonusSeconds(360)->addTrapBonusSeconds(240);

        self::assertSame(600, $round->getTrapBonusSeconds());
        self::assertSame(3600 + 600 + 720, $round->getScoreSeconds());
    }
}
