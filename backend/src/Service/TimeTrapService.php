<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\MessagePayload;
use App\Entity\GameTransitStation;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\TimeTrap;
use App\Enum\Side;
use App\Enum\TimeTrapAction;
use App\Enum\TimeTrapStatus;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\GeoDistance;
use App\Repository\GameTransitStationRepository;
use App\Repository\PlayerLocationRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Repository\TimeTrapRepository;
use App\RoundTiming;
use App\Storage\ImageStorageInterface;
use App\TimeTrapRules;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class TimeTrapService
{
    private const string UNNAMED_STATION = 'the station';

    public function __construct(
        private RoundRepository $rounds,
        private PlayerRepository $players,
        private RoundMembershipRepository $memberships,
        private TimeTrapRepository $traps,
        private GameTransitStationRepository $stations,
        private PlayerLocationRepository $locations,
        private ChatService $chatService,
        private ImageStorageInterface $imageStorage,
        private RoundClock $clock,
        private TimeTrapPublisher $publisher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The chat post and Mercure publish fire inside the transaction before commit (same trade-off as
     * QuestionService): a hub failure rolls the trap back, delivered SSE events stay delivered.
     */
    public function place(
        string $roundUuid,
        string $playerUuid,
        float $lat,
        float $lng,
        UploadedFile $cardPhoto,
    ): TimeTrap {
        return $this->entityManager->wrapInTransaction(
            function () use ($roundUuid, $playerUuid, $lat, $lng, $cardPhoto): TimeTrap {
                [$round, $player] = $this->resolveHider($roundUuid, $playerUuid);
                if (!$this->clock->isSeeking($round)) {
                    throw new FunctionalException(
                        message: 'Time traps only come into play once the seekers are hunting.',
                        errorKey: 'time_trap.not_seeking',
                    );
                }

                if ($this->traps->countByRound($round) >= TimeTrapRules::MAX_TRAPS_PER_ROUND) {
                    throw new FunctionalException(
                        message: 'This round already holds the maximum number of time traps.',
                        errorKey: 'time_trap.limit_reached',
                    );
                }

                $station = $this->nearestStation($round, $lat, $lng);
                // Store before commit: a storage failure must not leave an armed, unannounced trap.
                $imageRef = $this->imageStorage->store($round->getGame()->getUuid(), $cardPhoto);

                $trap = new TimeTrap($round, $player, $station);
                $this->traps->save($trap);

                $payload = $this->placedMessage($trap);
                $this->chatService->postCardPlay(
                    game: $round->getGame(),
                    sender: $player,
                    imageRef: $imageRef,
                    body: $payload->body,
                    bodyKey: $payload->bodyKey,
                    bodyArgs: $payload->bodyArgs,
                );
                $this->publisher->publish($trap, TimeTrapAction::Placed);

                return $trap;
            },
        );
    }

    /**
     * Runs on seeker location ingest, the hook endgame detection already uses. Frozen seekers are
     * not travelling, so a Move window suspends it; a detection is a prompt, not a verdict: no
     * attempt is made to prove the seeker was on a transit line.
     */
    public function checkTrip(Round $round, Player $seeker, Point $point): void
    {
        $this->entityManager->wrapInTransaction(function () use ($round, $seeker, $point): void {
            if ($this->clock->isMoveWindowOpen($round) || !$this->clock->isSeeking($round) || $seeker->hasLeft()) {
                return;
            }

            [$from, $to, $speedKmh] = $this->segmentFor($round, $seeker, $point);
            $tripped = $this->traps->findTrippedUuids(
                $round,
                $from,
                $to,
                TimeTrapRules::TRIP_RADIUS_METERS,
                new \DateTimeImmutable(sprintf('-%d minutes', TimeTrapRules::REDETECT_COOLDOWN_MINUTES)),
            );

            $trap = $tripped === [] ? null : $this->traps->findOneByUuid($tripped[0]);
            if ($trap === null) {
                return;
            }

            $this->markPending($trap, $seeker, $speedKmh);
        });
    }

    public function resolve(string $trapUuid, string $playerUuid, bool $confirmed): TimeTrap
    {
        return $this->entityManager->wrapInTransaction(function () use ($trapUuid, $playerUuid, $confirmed): TimeTrap {
            $trap = $this->traps->findOneByUuid($trapUuid);
            if ($trap === null) {
                throw new EntityNotFoundException(message: 'Time trap not found.', errorKey: 'time_trap.not_found');
            }

            $player = $this->players->findOneByUuid($playerUuid);
            if ($player === null) {
                throw new EntityNotFoundException(message: 'Player not found.', errorKey: 'player.not_found');
            }

            $membership = $this->memberships->findOneByRoundAndPlayer($trap->getRound(), $player);
            if ($membership === null || $membership->getSide() !== Side::Seeker) {
                throw new FunctionalException(
                    message: 'Only a seeker may resolve a time trap.',
                    errorKey: 'time_trap.not_seeker',
                );
            }

            // The cutoff binds a pending detection too: a resolution must not move an already-ranked score.
            if (!$this->clock->isSeeking($trap->getRound())) {
                throw new FunctionalException(
                    message: 'Time traps can only be resolved while the seekers are hunting.',
                    errorKey: 'time_trap.not_seeking',
                );
            }

            if ($trap->getStatus() !== TimeTrapStatus::Pending) {
                throw new FunctionalException(
                    message: 'This time trap is not awaiting a resolution.',
                    errorKey: 'time_trap.not_pending',
                );
            }

            return $confirmed ? $this->confirm($trap) : $this->dismiss($trap);
        });
    }

    /**
     * @return array{Round, Player}
     */
    private function resolveHider(string $roundUuid, string $playerUuid): array
    {
        $round = $this->rounds->findOneByUuid($roundUuid);
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $player = $this->players->findOneByUuid($playerUuid);
        if ($player === null) {
            throw new EntityNotFoundException(message: 'Player not found.', errorKey: 'player.not_found');
        }

        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($membership === null || $membership->getSide() !== Side::Hider) {
            throw new FunctionalException(
                message: 'Only a hider may place a time trap.',
                errorKey: 'time_trap.not_hider',
            );
        }

        return [$round, $player];
    }

    private function nearestStation(Round $round, float $lat, float $lng): GameTransitStation
    {
        $inRange = abs($lat) <= 90.0 && abs($lng) <= 180.0;
        $station = $inRange
            ? $this->stations->findNearestWithin(
                $round->getGame(),
                new Point($lng, $lat),
                TimeTrapRules::SNAP_RADIUS_METERS,
            )
            : null;

        if ($station === null) {
            throw new FunctionalException(
                message: 'A time trap has to be placed on a transit station.',
                errorKey: 'time_trap.no_station',
            );
        }

        return $station;
    }

    /**
     * @return array{Point, Point, int}
     */
    private function segmentFor(Round $round, Player $seeker, Point $point): array
    {
        $latest = $this->locations->findLatestByRoundAndPlayer($round, $seeker);
        $previous = $latest === null
            ? null
            : $this->locations->findPreviousByRoundAndPlayer($round, $seeker, $latest);
        if ($latest === null || $previous === null) {
            return [$point, $point, 0];
        }

        $seconds = $latest->getRecordedAt()->getTimestamp() - $previous->getRecordedAt()->getTimestamp();
        if ($seconds <= 0 || $seconds > TimeTrapRules::MAX_SEGMENT_SECONDS) {
            return [$point, $point, 0];
        }

        $from = $previous->getPoint();

        return [$from, $point, self::speedKmh($from, $point, $seconds)];
    }

    private static function speedKmh(Point $from, Point $to, int $seconds): int
    {
        return $seconds <= 0 ? 0 : (int) round(GeoDistance::metersBetween($from, $to) / $seconds * 3.6);
    }

    /** Freeze the value at the pass so a slow resolution cannot inflate it. */
    private function markPending(TimeTrap $trap, Player $seeker, int $speedKmh): void
    {
        if (!$this->traps->claimStatus($trap, TimeTrapStatus::Armed, TimeTrapStatus::Pending)) {
            return;
        }

        $now = new \DateTimeImmutable();
        $trap->setStatus(TimeTrapStatus::Pending)
            ->setDetectedAt($now)
            ->setDetectedByPlayer($seeker)
            ->setFrozenValueSeconds($trap->valueSecondsAt($now));
        $this->traps->save($trap);

        $payload = $this->detectedMessage($trap, $seeker, $speedKmh);
        $this->chatService->postSystem(
            game: $trap->getRound()->getGame(),
            body: $payload->body,
            bodyKey: $payload->bodyKey,
            bodyArgs: $payload->bodyArgs,
        );
        $this->publisher->publish($trap, TimeTrapAction::Detected);
    }

    private function confirm(TimeTrap $trap): TimeTrap
    {
        return $this->entityManager->wrapInTransaction(function () use ($trap): TimeTrap {
            $this->claimPendingOrFail($trap, TimeTrapStatus::Sprung);

            $awarded = $trap->getFrozenValueSeconds() ?? 0;
            $trap->setStatus(TimeTrapStatus::Sprung)->setAwardedSeconds($awarded);
            $this->traps->save($trap);

            $round = $trap->getRound();
            $this->rounds->creditTrapBonusSeconds($round, $awarded);

            $payload = $this->sprungMessage($trap, $awarded);
            $this->chatService->postSystem(
                game: $round->getGame(),
                body: $payload->body,
                bodyKey: $payload->bodyKey,
                bodyArgs: $payload->bodyArgs,
            );
            $this->publisher->publish($trap, TimeTrapAction::Sprung);

            return $trap;
        });
    }

    /** Post dismissals too, so hiders learn a claim was refused rather than losing it. */
    private function dismiss(TimeTrap $trap): TimeTrap
    {
        return $this->entityManager->wrapInTransaction(function () use ($trap): TimeTrap {
            $this->claimPendingOrFail($trap, TimeTrapStatus::Armed);

            $trap->setStatus(TimeTrapStatus::Armed)
                ->setRearmedAt(new \DateTimeImmutable())
                ->setDetectedAt(null)
                ->setDetectedByPlayer(null)
                ->setFrozenValueSeconds(null);
            $this->traps->save($trap);

            $payload = $this->dismissedMessage($trap);
            $this->chatService->postSystem(
                game: $trap->getRound()->getGame(),
                body: $payload->body,
                bodyKey: $payload->bodyKey,
                bodyArgs: $payload->bodyArgs,
            );
            $this->publisher->publish($trap, TimeTrapAction::Dismissed);

            return $trap;
        });
    }

    private function claimPendingOrFail(TimeTrap $trap, TimeTrapStatus $to): void
    {
        if (!$this->traps->claimStatus($trap, TimeTrapStatus::Pending, $to)) {
            throw new FunctionalException(
                message: 'This time trap is not awaiting a resolution.',
                errorKey: 'time_trap.not_pending',
            );
        }
    }

    private function placedMessage(TimeTrap $trap): MessagePayload
    {
        $size = $trap->getRound()->getGame()->getSize();
        $increment = RoundTiming::timeTrapIncrementMinutes($size);
        $interval = RoundTiming::timeTrapIntervalMinutes($size);
        $station = self::stationLabel($trap);

        return new MessagePayload(
            bodyKey: 'trap.placed',
            bodyArgs: ['station' => $station, 'increment' => $increment, 'interval' => $interval],
            body: sprintf(
                'A time trap is set at %s: it gains %d min every %d min until a seeker passes through it.',
                $station,
                $increment,
                $interval,
            ),
        );
    }

    private function detectedMessage(TimeTrap $trap, Player $seeker, int $speedKmh): MessagePayload
    {
        $station = self::stationLabel($trap);
        $seekerName = $seeker->getDisplayName();
        $minutes = intdiv($trap->getFrozenValueSeconds() ?? 0, 60);

        return new MessagePayload(
            bodyKey: 'trap.detected',
            bodyArgs: [
                'station' => $station,
                'seeker' => $seekerName,
                'minutes' => $minutes,
                'speed' => $speedKmh,
            ],
            body: sprintf(
                'Time trap at %s: %s passed it at about %d km/h, worth %d min. Seekers, confirm or dismiss it.',
                $station,
                $seekerName,
                $speedKmh,
                $minutes,
            ),
        );
    }

    private function sprungMessage(TimeTrap $trap, int $awardedSeconds): MessagePayload
    {
        $station = self::stationLabel($trap);
        $minutes = intdiv($awardedSeconds, 60);

        return new MessagePayload(
            bodyKey: 'trap.sprung',
            bodyArgs: ['station' => $station, 'minutes' => $minutes],
            body: sprintf('The time trap at %s was confirmed and adds %d min to the round.', $station, $minutes),
        );
    }

    private function dismissedMessage(TimeTrap $trap): MessagePayload
    {
        $station = self::stationLabel($trap);

        return new MessagePayload(
            bodyKey: 'trap.dismissed',
            bodyArgs: ['station' => $station],
            body: sprintf('The time trap at %s was dismissed and is armed again.', $station),
        );
    }

    private static function stationLabel(TimeTrap $trap): string
    {
        return $trap->getStationName() ?? self::UNNAMED_STATION;
    }
}
