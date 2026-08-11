<?php

declare(strict_types=1);

namespace App\Service;

use App\DateFormat;
use App\Dto\MessagePayload;
use App\Dto\ScoreBonus;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\GameRepository;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\RoundTiming;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class RoundService
{
    private const int SECONDS_PER_MINUTE = 60;
    private const int SECONDS_PER_HOUR = 3600;

    public function __construct(
        private RoundRepository $rounds,
        private GameRepository $games,
        private RoundMembershipRepository $memberships,
        private PlayerRepository $players,
        private HidingZoneRepository $hidingZones,
        private ChatService $chatService,
        private MercureJwtService $mercure,
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function start(string $roundUuid, ?int $hidingPeriodMinutes = null): Round
    {
        $round = $this->findOrFail($roundUuid);
        if ($round->getStatus() !== RoundStatus::Lobby) {
            throw new FunctionalException(
                message: 'Round has already been started.',
                errorKey: 'round.already_started',
            );
        }

        $now = new \DateTimeImmutable();
        $minutes = $hidingPeriodMinutes ?? RoundTiming::hidingPeriodMinutes($round->getGame()->getSize());

        $round
            ->setHidingPeriodStartedAt($now)
            ->setHidingPeriodEndsAt($now->modify("+{$minutes} minutes"))
            ->setStatus(RoundStatus::Hiding);
        $this->rounds->save($round);

        $this->warnAboutPlayersWithoutSide($round);
        $this->publishTimer($round);

        return $round;
    }

    public function createNextRound(string $gameUuid): Round
    {
        $game = $this->games->findOneByUuid($gameUuid);
        if ($game === null) {
            throw new EntityNotFoundException(
                message: 'Game not found.',
                errorKey: 'game.not_found',
            );
        }

        if ($this->rounds->findActiveByGame($game) !== null) {
            throw new FunctionalException(
                message: 'Finish the current round before starting a new one.',
                errorKey: 'round.finish_current_first',
            );
        }

        $previous = $this->rounds->findLatestByGame($game);
        $round = new Round($game);
        $this->rounds->save($round, flush: false);
        $this->seedSwappedMemberships($previous, $round);
        $this->rounds->save($round);
        // Other devices need the new round's uuid/status to move into the lobby before it starts (team-switch window).
        $this->publishTimer($round);

        return $round;
    }

    /**
     * Deliberately silent unless the phase actually changed: every timer event costs each device an
     * HTTP round-trip, and clients run the countdown off hidingPeriodEndsAt on their own clock.
     */
    public function tick(Round $round): void
    {
        $this->currentStatus($round);
    }

    public function currentStatus(Round $round): RoundStatus
    {
        $endsAt = $round->getHidingPeriodEndsAt();
        $expired = $round->getStatus() === RoundStatus::Hiding
            && $endsAt !== null
            && new \DateTimeImmutable() >= $endsAt;

        if (!$expired) {
            return $round->getStatus();
        }

        $movePeriodClosed = $round->isInMovePeriod();
        $round->setStatus(RoundStatus::Seeking)->setInMovePeriod(false);
        $this->rounds->save($round);
        if ($movePeriodClosed) {
            $this->chatService->postSystem(
                game: $round->getGame(),
                body: 'The move period is over. Seekers are free again and the hiding timer is running.',
                bodyKey: 'round.move_period_over',
            );
        }
        $this->publishTimer($round);

        return RoundStatus::Seeking;
    }

    /**
     * Move pauses the hiding timer: the seconds already earned are banked so the fresh hiding period
     * costs the hiders nothing, and the seekers stay frozen until it elapses.
     */
    public function openMovePeriod(Round $round): void
    {
        $endsAt = $round->getHidingPeriodEndsAt();
        if ($endsAt === null) {
            throw new \LogicException('Cannot open a move period on a round that never started hiding.');
        }

        $now = new \DateTimeImmutable();
        $minutes = RoundTiming::movePeriodMinutes($round->getGame()->getSize());
        $earned = max(0, $now->getTimestamp() - $endsAt->getTimestamp());

        $round
            ->setBankedSeekingSeconds($round->getBankedSeekingSeconds() + $earned)
            ->setHidingPeriodStartedAt($now)
            ->setHidingPeriodEndsAt($now->modify("+{$minutes} minutes"))
            ->setInMovePeriod(true)
            ->setStatus(RoundStatus::Hiding);
        $this->rounds->save($round);

        $this->publishTimer($round);
    }

    public function stop(
        string $roundUuid,
        ?ScoreBonus $bonus = null,
        ?int $declaredSeconds = null,
        bool $caught = false,
    ): Round {
        $round = $this->findOrFail($roundUuid);
        $status = $this->currentStatus($round);
        if ($status !== RoundStatus::Hiding && $status !== RoundStatus::Seeking) {
            throw new FunctionalException(
                message: 'Round is not currently running.',
                errorKey: 'round.not_running',
            );
        }

        // Aborting leaves seekingEndedAt null, mid-hiding or mid-seeking: only a declared find is a score.
        if ($caught && $status === RoundStatus::Seeking) {
            $round->setSeekingEndedAt($this->caughtAt($round, $declaredSeconds));
        }
        $round->setStatus(RoundStatus::Ended)->setBonus($bonus ?? new ScoreBonus())->setCaught($caught);
        if ($caught) {
            $round->setHiderNames($this->hiderNames($round));
        }
        $this->rounds->save($round);

        $this->announceHidingTime($round);
        $this->publishTimer($round);

        return $round;
    }

    /**
     * Carries every round field a client renders, so receiving it replaces GET /rounds/{uuid} rather
     * than prompting one on every device. The game-wide topic still carries no location data: the
     * radius is a length and hasHidingZone a bare flag, neither of which places the zone.
     */
    public function publishTimer(Round $round): void
    {
        $game = $round->getGame();
        $zone = $this->hidingZones->findOneByRound($round);
        $payload = json_encode([
            'type' => 'timer',
            'roundUuid' => $round->getUuid(),
            'status' => $round->getStatus()->value,
            'hidingPeriodStartedAt' => $this->formatOrNull($round->getHidingPeriodStartedAt()),
            'hidingPeriodEndsAt' => $this->formatOrNull($round->getHidingPeriodEndsAt()),
            'seekingEndedAt' => $this->formatOrNull($round->getSeekingEndedAt()),
            'bankedSeekingSeconds' => $round->getBankedSeekingSeconds(),
            'inMovePeriod' => $round->isInMovePeriod(),
            'hidingTimeSeconds' => $round->getHidingTimeSeconds(),
            'scoreSeconds' => $round->getScoreSeconds(),
            'hasHidingZone' => $zone !== null,
            'hidingRadiusMeters' => $zone?->getRadiusMeters()
                ?? RoundTiming::defaultRadiusMeters($game->getSize(), $game->getEdition()),
        ], JSON_THROW_ON_ERROR);

        $this->hub->publish(new Update($this->mercure->timerTopic($game), $payload));
    }

    private function findOrFail(string $roundUuid): Round
    {
        $round = $this->rounds->findOneByUuid($roundUuid);
        if ($round === null) {
            throw new EntityNotFoundException(
                message: 'Round not found.',
                errorKey: 'round.not_found',
            );
        }

        return $round;
    }

    private function seedSwappedMemberships(?Round $previous, Round $next): void
    {
        if ($previous === null) {
            return;
        }
        foreach ($this->memberships->findByRound($previous) as $m) {
            // Leaving already drops memberships; this keeps a stale one from seeding an absent side.
            if ($m->getPlayer()->hasLeft()) {
                continue;
            }
            $swapped = match ($m->getSide()) {
                Side::Hider => Side::Seeker,
                Side::Seeker => Side::Hider,
            };
            $this->memberships->save(new RoundMembership($next, $m->getPlayer(), $swapped), flush: false);
        }
    }

    // An incomplete roster must not block the start, but it locks those players out of pinging: log it.
    private function warnAboutPlayersWithoutSide(Round $round): void
    {
        $withSide = array_map(
            static fn (RoundMembership $m): string => $m->getPlayer()->getUuid(),
            $this->memberships->findByRound($round),
        );
        $missing = array_filter(
            $this->players->findByGameOrdered($round->getGame()),
            static fn (Player $player): bool => !in_array($player->getUuid(), $withSide, true),
        );

        if ($missing !== []) {
            $names = array_map(static fn (Player $player): string => $player->getDisplayName(), $missing);
            $this->logger->warning('Round started with players who have not chosen a side', [
                'roundUuid' => $round->getUuid(),
                'players' => array_values($names),
            ]);
        }
    }

    /**
     * The app freezes the clock while the hiders total their bonus cards, so it declares the time it had
     * on screen. Anything above what really elapsed is ignored: a client may only score itself short.
     */
    private function caughtAt(Round $round, ?int $declaredSeconds): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        $endsAt = $round->getHidingPeriodEndsAt();
        if ($declaredSeconds === null || $endsAt === null) {
            return $now;
        }

        $banked = $round->getBankedSeekingSeconds();
        $elapsed = $banked + max(0, $now->getTimestamp() - $endsAt->getTimestamp());
        $offset = min(max($declaredSeconds, $banked), $elapsed) - $banked;

        return $endsAt->modify("+{$offset} seconds");
    }

    /**
     * @return list<string>
     */
    private function hiderNames(Round $round): array
    {
        return array_map(
            static fn (RoundMembership $m): string => $m->getPlayer()->getDisplayName(),
            $this->memberships->findHidersByRound($round),
        );
    }

    private function announceHidingTime(Round $round): void
    {
        if (!$round->isCaught() || $round->getSeekingEndedAt() === null) {
            return;
        }

        $payload = $this->hidingTimeMessage($round);
        $this->chatService->postSystem(
            game: $round->getGame(),
            body: $payload->body,
            bodyKey: $payload->bodyKey,
            bodyArgs: $payload->bodyArgs,
        );
    }

    private function hidingTimeMessage(Round $round): MessagePayload
    {
        $seconds = $round->getHidingTimeSeconds();
        // The total is read off the round rather than recomputed, so chat and the leaderboard cannot drift.
        $total = $round->getScoreSeconds();
        if ($seconds === null || $total === null) {
            throw new \LogicException('Cannot compute hiding time before the round has both timestamps.');
        }

        $bonus = $round->getBonus();
        $trapSeconds = $round->getTrapBonusSeconds();
        if (!$bonus->isNone() || $trapSeconds > 0) {
            return $this->scoreWithBonusMessage($seconds, $bonus, $trapSeconds, $total);
        }

        return new MessagePayload(
            bodyKey: 'round.hiding_time',
            bodyArgs: ['seconds' => max(0, $seconds)],
            body: sprintf('Hiding time: %s', self::duration($seconds)),
        );
    }

    private function scoreWithBonusMessage(
        int $seconds,
        ScoreBonus $bonus,
        int $trapSeconds,
        int $total,
    ): MessagePayload {
        $trapMinutes = intdiv(max(0, $trapSeconds), self::SECONDS_PER_MINUTE);

        return new MessagePayload(
            bodyKey: 'round.hiding_time_with_bonus',
            bodyArgs: [
                'seconds' => max(0, $seconds),
                'bonusMin' => $bonus->minutes,
                'percent' => $bonus->percent,
                'trapMin' => $trapMinutes,
                'totalSeconds' => max(0, $total),
            ],
            body: sprintf(
                'Hiding time: %s, bonuses +%d min +%d%%, time traps +%d min, final time: %s',
                self::duration($seconds),
                $bonus->minutes,
                $bonus->percent,
                $trapMinutes,
                self::duration($total),
            ),
        );
    }

    /**
     * Only the English fallback body needs this: the args carry raw seconds so each client words the
     * duration in its own locale. Most rounds run past an hour, hence the hours field.
     */
    private static function duration(int $seconds): string
    {
        $safe = max(0, $seconds);
        $hours = intdiv($safe, self::SECONDS_PER_HOUR);
        $minutes = intdiv($safe % self::SECONDS_PER_HOUR, self::SECONDS_PER_MINUTE);
        if ($hours > 0) {
            return sprintf('%d h %d min %02d s', $hours, $minutes, $safe % self::SECONDS_PER_MINUTE);
        }

        return sprintf('%d min %02d s', $minutes, $safe % self::SECONDS_PER_MINUTE);
    }

    private function formatOrNull(?\DateTimeImmutable $at): ?string
    {
        return $at?->format(DateFormat::ISO8601_UTC);
    }
}
