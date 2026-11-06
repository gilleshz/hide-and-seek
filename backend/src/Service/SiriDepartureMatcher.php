<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Matches SIRI visits to scheduled departures without relying on GTFS trip ids: a visit fits when
 * its line and stop codes agree and its aimed time is close to the scheduled one. A destination
 * name disambiguates merged stations and directions; outside them a stop sequence is enough, and
 * a signal-less visit is rejected at a merged station rather than guessed.
 */
final readonly class SiriDepartureMatcher
{
    private const int AIM_TOLERANCE_SECONDS = 180;

    /**
     * @param array{
     *     lineCodes: list<string>,
     *     stopCodes: list<string>,
     *     scheduledAt: int,
     *     tripRef: string,
     *     seq: int,
     *     headsign: string,
     *     strict: bool
     * } $candidate
     * @param list<SiriVisit> $visits
     */
    public function match(array $candidate, array $visits): ?int
    {
        $lineCodes = $this->normalizeAll($candidate['lineCodes']);
        $stopCodes = $this->normalizeAll($candidate['stopCodes']);
        $tripRef = SiriRef::normalized($candidate['tripRef']);
        if ($lineCodes === [] || $stopCodes === []) {
            return null;
        }

        $best = null;
        $bestDelta = PHP_INT_MAX;
        $bestExact = false;
        foreach ($visits as $visit) {
            if ($visit->cancelled || !$this->isDisambiguated($visit, $candidate)) {
                continue;
            }
            $expected = $visit->expectedAt ?? $visit->aimedAt;
            if (!isset($lineCodes[SiriRef::normalized($visit->lineCode)])) {
                continue;
            }
            if (!isset($stopCodes[SiriRef::normalized($visit->stopCode)])) {
                continue;
            }
            $delta = abs($visit->aimedAt - $candidate['scheduledAt']);
            if ($delta > self::AIM_TOLERANCE_SECONDS) {
                continue;
            }
            $exact = $tripRef !== '' && $visit->tripRef !== null
                && SiriRef::normalized($visit->tripRef) === $tripRef;
            if ($best === null || ($exact && !$bestExact) || ($exact === $bestExact && $delta < $bestDelta)) {
                $best = $expected;
                $bestDelta = $delta;
                $bestExact = $exact;
            }
        }

        return $best;
    }

    /**
     * @param array{seq: int, headsign: string, strict: bool} $candidate
     */
    private function isDisambiguated(SiriVisit $visit, array $candidate): bool
    {
        if ($this->hasText($visit->destination) && trim($candidate['headsign']) !== '') {
            return $this->destinationMatches((string) $visit->destination, $candidate['headsign']);
        }
        if ($candidate['strict']) {
            return false;
        }
        if ($visit->stopSequence !== null && $candidate['seq'] > 0) {
            return $visit->stopSequence === $candidate['seq'];
        }

        return true;
    }

    private function destinationMatches(string $destination, string $headsign): bool
    {
        $left = $this->normalizeText($destination);
        $right = $this->normalizeText($headsign);

        return $left !== '' && $right !== ''
            && (str_contains(' ' . $left . ' ', ' ' . $right . ' ')
                || str_contains(' ' . $right . ' ', ' ' . $left . ' '));
    }

    private function normalizeText(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = strtolower($ascii === false ? $value : $ascii);
        $parts = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? implode(' ', $parts) : '';
    }

    private function hasText(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    /**
     * @param list<string> $refs
     *
     * @return array<string, true>
     */
    private function normalizeAll(array $refs): array
    {
        $normalized = [];
        foreach ($refs as $ref) {
            $code = SiriRef::normalized($ref);
            if ($code !== '') {
                $normalized[$code] = true;
            }
        }

        return $normalized;
    }
}
