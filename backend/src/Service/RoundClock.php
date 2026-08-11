<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Round;
use App\Enum\RoundStatus;

/**
 * Reads where a round stands without waiting for a tick to persist it, so the zone, question and
 * endgame paths all agree about whether seekers are hunting.
 */
final readonly class RoundClock
{
    /**
     * An elapsed hiding period counts as seeking even before RoundService::currentStatus has flipped
     * the stored status, so a client that beats the tick is never told hiding is still running.
     */
    public function isSeeking(Round $round): bool
    {
        $endsAt = $round->getHidingPeriodEndsAt();

        return match ($round->getStatus()) {
            RoundStatus::Seeking => true,
            RoundStatus::Hiding => $endsAt !== null && new \DateTimeImmutable() >= $endsAt,
            RoundStatus::Lobby, RoundStatus::Ended => false,
        };
    }

    /**
     * The window a Move grants, during which seekers are frozen: no questions and no capture.
     */
    public function isMoveWindowOpen(Round $round): bool
    {
        return $round->isInMovePeriod() && !$this->isSeeking($round);
    }
}
