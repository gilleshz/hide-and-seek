<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SiriDepartureMatcher;
use App\Service\SiriVisit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SiriDepartureMatcherTest extends TestCase
{
    private const int SCHEDULED = 1_800_000_000;

    #[Test]
    public function itReturnsTheExpectedTimeOfTheMatchingVisit(): void
    {
        $match = new SiriDepartureMatcher()->match(
            $this->candidate(),
            [new SiriVisit('STIF:Line::L1:', 'STIF:Stop:orig-1:', null, self::SCHEDULED, self::SCHEDULED + 120)],
        );

        self::assertSame(self::SCHEDULED + 120, $match);
    }

    #[Test]
    public function itRejectsAMismatchedLineStopOrTime(): void
    {
        $matcher = new SiriDepartureMatcher();
        $visits = [
            new SiriVisit('L2', 'orig-1', null, self::SCHEDULED, self::SCHEDULED + 60),
            new SiriVisit('L1', 'orig-2', null, self::SCHEDULED, self::SCHEDULED + 60),
            new SiriVisit('L1', 'orig-1', null, self::SCHEDULED + 600, self::SCHEDULED + 660),
        ];

        self::assertNull($matcher->match($this->candidate(), $visits));
    }

    #[Test]
    public function itPrefersAnExactTripRefOverANearerAim(): void
    {
        $match = new SiriDepartureMatcher()->match(
            $this->candidate(),
            [
                new SiriVisit('L1', 'orig-1', 'other-trip', self::SCHEDULED, self::SCHEDULED + 30),
                new SiriVisit('L1', 'orig-1', 'T1', self::SCHEDULED + 60, self::SCHEDULED + 90),
            ],
        );

        self::assertSame(self::SCHEDULED + 90, $match);
    }

    #[Test]
    public function itBreaksTiesWithTheNearestAimedTime(): void
    {
        $match = new SiriDepartureMatcher()->match(
            $this->candidate(),
            [
                new SiriVisit('L1', 'orig-1', null, self::SCHEDULED - 100, self::SCHEDULED - 80),
                new SiriVisit('L1', 'orig-1', null, self::SCHEDULED + 20, self::SCHEDULED + 40),
            ],
        );

        self::assertSame(self::SCHEDULED + 40, $match);
    }

    #[Test]
    public function itSkipsCancelledVisits(): void
    {
        $match = new SiriDepartureMatcher()->match(
            $this->candidate(),
            [new SiriVisit('L1', 'orig-1', 'T1', self::SCHEDULED, self::SCHEDULED + 60, true)],
        );

        self::assertNull($match);
    }

    #[Test]
    public function itFallsBackToTheAimedTimeWhenTheVisitHasNoExpectedTime(): void
    {
        $match = new SiriDepartureMatcher()->match(
            $this->candidate(),
            [new SiriVisit('L1', 'orig-1', 'T1', self::SCHEDULED, null)],
        );

        self::assertSame(self::SCHEDULED, $match);
    }

    #[Test]
    public function itReturnsNullWithoutLineOrStopCodes(): void
    {
        $matcher = new SiriDepartureMatcher();
        $visits = [new SiriVisit('L1', 'orig-1', null, self::SCHEDULED, self::SCHEDULED + 60)];

        self::assertNull($matcher->match([
            'lineCodes' => [],
            'stopCodes' => ['orig-1'],
            'scheduledAt' => self::SCHEDULED,
            'tripRef' => '',
            'seq' => 0,
            'headsign' => '',
            'strict' => false,
        ], $visits));
        self::assertNull($matcher->match([
            'lineCodes' => ['L1'],
            'stopCodes' => [],
            'scheduledAt' => self::SCHEDULED,
            'tripRef' => '',
            'seq' => 0,
            'headsign' => '',
            'strict' => false,
        ], $visits));
    }

    #[Test]
    public function itRejectsAVisitWithADifferentDestination(): void
    {
        $match = new SiriDepartureMatcher()->match(
            $this->candidate(headsign: 'Gare de Lyon'),
            [new SiriVisit('L1', 'orig-1', null, self::SCHEDULED, self::SCHEDULED + 60, false, null, 'Part-Dieu')],
        );

        self::assertNull($match);
    }

    #[Test]
    public function itRejectsAVisitWithADifferentStopSequence(): void
    {
        $match = new SiriDepartureMatcher()->match(
            $this->candidate(seq: 4),
            [new SiriVisit('L1', 'orig-1', null, self::SCHEDULED, self::SCHEDULED + 60, false, 9, null)],
        );

        self::assertNull($match);
    }

    #[Test]
    public function itKeepsAVisitWhenTheOrderNumberingDiffersButTheDestinationMatches(): void
    {
        $match = new SiriDepartureMatcher()->match(
            $this->candidate(seq: 4, headsign: 'Gare de Lyon'),
            [new SiriVisit('L1', 'orig-1', null, self::SCHEDULED, self::SCHEDULED + 60, false, 9, 'Gare de Lyon')],
        );

        self::assertSame(self::SCHEDULED + 60, $match);
    }

    #[Test]
    public function itRejectsAConflictingDestinationDespiteASequenceMatch(): void
    {
        $match = new SiriDepartureMatcher()->match(
            $this->candidate(seq: 4, headsign: 'Gare de Lyon'),
            [new SiriVisit('L1', 'orig-1', null, self::SCHEDULED, self::SCHEDULED + 60, false, 4, 'Part-Dieu')],
        );

        self::assertNull($match);
    }

    #[Test]
    public function itMatchesOnTheSequenceAloneOutsideAMergedStation(): void
    {
        $match = new SiriDepartureMatcher()->match(
            $this->candidate(seq: 4, headsign: 'Part-Dieu'),
            [new SiriVisit('L1', 'orig-1', null, self::SCHEDULED, self::SCHEDULED + 60, false, 4, null)],
        );

        self::assertSame(self::SCHEDULED + 60, $match);
    }

    #[Test]
    public function itRejectsASequenceOnlyVisitAtAMergedStation(): void
    {
        $match = new SiriDepartureMatcher()->match(
            $this->candidate(seq: 4, headsign: 'Part-Dieu', strict: true),
            [new SiriVisit('L1', 'orig-1', null, self::SCHEDULED, self::SCHEDULED + 60, false, 4, null)],
        );

        self::assertNull($match);
    }

    #[Test]
    public function itMatchesTheDestinationAccentInsensitively(): void
    {
        $visit = new SiriVisit(
            'L1',
            'orig-1',
            null,
            self::SCHEDULED,
            self::SCHEDULED + 60,
            false,
            null,
            'hopital schuman',
        );

        $match = new SiriDepartureMatcher()->match($this->candidate(headsign: 'Hôpital Schuman'), [$visit]);

        self::assertSame(self::SCHEDULED + 60, $match);
    }

    #[Test]
    public function itSkipsAStrictCandidateWithoutADisambiguator(): void
    {
        $match = new SiriDepartureMatcher()->match(
            $this->candidate(strict: true),
            [new SiriVisit('L1', 'orig-1', null, self::SCHEDULED, self::SCHEDULED + 60)],
        );

        self::assertNull($match);
    }

    #[Test]
    public function itMatchesProductionShapedIdfmAndTclIds(): void
    {
        $matcher = new SiriDepartureMatcher();

        $idfmVisit = new SiriVisit('IDFM:C01624', 'IDFM:22104', null, self::SCHEDULED, self::SCHEDULED + 60);
        self::assertSame(self::SCHEDULED + 60, $matcher->match([
            'lineCodes' => ['IDFM:C01624'],
            'stopCodes' => ['IDFM:22104'],
            'scheduledAt' => self::SCHEDULED,
            'tripRef' => '',
            'seq' => 0,
            'headsign' => '',
            'strict' => false,
        ], [$idfmVisit]));

        $tclVisit = new SiriVisit(
            'TCL:Line::T1:',
            'TCL:Stop:102',
            null,
            self::SCHEDULED,
            self::SCHEDULED + 60,
            false,
            4,
            null,
        );
        self::assertSame(self::SCHEDULED + 60, $matcher->match([
            'lineCodes' => ['102', 'T1'],
            'stopCodes' => ['102'],
            'scheduledAt' => self::SCHEDULED,
            'tripRef' => '',
            'seq' => 4,
            'headsign' => 'Part-Dieu',
            'strict' => false,
        ], [$tclVisit]));
    }

    /**
     * @return array{
     *     lineCodes: list<string>,
     *     stopCodes: list<string>,
     *     scheduledAt: int,
     *     tripRef: string,
     *     seq: int,
     *     headsign: string,
     *     strict: bool
     * }
     */
    private function candidate(int $seq = 0, string $headsign = '', bool $strict = false): array
    {
        return [
            'lineCodes' => ['STIF:Line::L1:'],
            'stopCodes' => ['STIF:StopPoint:Q:orig-1:'],
            'scheduledAt' => self::SCHEDULED,
            'tripRef' => 'T1',
            'seq' => $seq,
            'headsign' => $headsign,
            'strict' => $strict,
        ];
    }
}
