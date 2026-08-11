<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TimeTrapStatus;
use App\Repository\TimeTrapRepository;
use App\RoundTiming;
use Doctrine\ORM\Mapping as ORM;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TimeTrapRepository::class)]
#[ORM\Table(name: 'time_traps')]
#[ORM\Index(name: 'idx_time_trap_round', columns: ['round_id'])]
class TimeTrap
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Round::class)]
    #[ORM\JoinColumn(name: 'round_id', nullable: false, onDelete: 'CASCADE')]
    private Round $round;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    #[ORM\JoinColumn(name: 'placed_by_player_id', nullable: false, onDelete: 'CASCADE')]
    private Player $placedByPlayer;

    #[ORM\ManyToOne(targetEntity: GameTransitStation::class)]
    #[ORM\JoinColumn(name: 'station_id', nullable: false, onDelete: 'CASCADE')]
    private GameTransitStation $station;

    /** Snapshotted so a re-imported transit overlay cannot rewrite what chat already announced. */
    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $stationName = null;

    #[ORM\Column(type: 'geography_point')]
    private Point $point;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $placedAt;

    #[ORM\Column(type: 'string', length: 16, enumType: TimeTrapStatus::class)]
    private TimeTrapStatus $status;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $detectedAt = null;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    #[ORM\JoinColumn(name: 'detected_by_player_id', nullable: true, onDelete: 'SET NULL')]
    private ?Player $detectedByPlayer = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $frozenValueSeconds = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rearmedAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $awardedSeconds = null;

    public function __construct(Round $round, Player $placedByPlayer, GameTransitStation $station)
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->round = $round;
        $this->placedByPlayer = $placedByPlayer;
        $this->station = $station;
        $this->stationName = $station->getName();
        $this->point = $station->getPoint();
        $this->placedAt = new \DateTimeImmutable();
        $this->status = TimeTrapStatus::Armed;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getRound(): Round
    {
        return $this->round;
    }

    public function getPlacedByPlayer(): Player
    {
        return $this->placedByPlayer;
    }

    public function getStation(): GameTransitStation
    {
        return $this->station;
    }

    public function getStationName(): ?string
    {
        return $this->stationName;
    }

    public function getPoint(): Point
    {
        return $this->point;
    }

    public function getPlacedAt(): \DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function getStatus(): TimeTrapStatus
    {
        return $this->status;
    }

    public function setStatus(TimeTrapStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getDetectedAt(): ?\DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function setDetectedAt(?\DateTimeImmutable $detectedAt): self
    {
        $this->detectedAt = $detectedAt;

        return $this;
    }

    public function getDetectedByPlayer(): ?Player
    {
        return $this->detectedByPlayer;
    }

    public function setDetectedByPlayer(?Player $detectedByPlayer): self
    {
        $this->detectedByPlayer = $detectedByPlayer;

        return $this;
    }

    public function getFrozenValueSeconds(): ?int
    {
        return $this->frozenValueSeconds;
    }

    public function setFrozenValueSeconds(?int $frozenValueSeconds): self
    {
        $this->frozenValueSeconds = $frozenValueSeconds;

        return $this;
    }

    public function getRearmedAt(): ?\DateTimeImmutable
    {
        return $this->rearmedAt;
    }

    public function setRearmedAt(?\DateTimeImmutable $rearmedAt): self
    {
        $this->rearmedAt = $rearmedAt;

        return $this;
    }

    public function getAwardedSeconds(): ?int
    {
        return $this->awardedSeconds;
    }

    public function setAwardedSeconds(?int $awardedSeconds): self
    {
        $this->awardedSeconds = $awardedSeconds;

        return $this;
    }

    /**
     * Derived on read from the placement anchor, so no scheduled task can drift it. Worth nothing
     * for the first full interval, by the card's arithmetic rather than by rounding.
     */
    public function valueSecondsAt(\DateTimeImmutable $at): int
    {
        $size = $this->round->getGame()->getSize();
        $intervalSeconds = RoundTiming::timeTrapIntervalMinutes($size) * 60;
        $elapsed = $at->getTimestamp() - $this->placedAt->getTimestamp();
        if ($elapsed < $intervalSeconds) {
            return 0;
        }

        return intdiv($elapsed, $intervalSeconds) * RoundTiming::timeTrapIncrementMinutes($size) * 60;
    }

    /**
     * What the trap is worth to a reader now. Accrual stops the moment a pass is detected, so a slow
     * resolution never inflates the payout and a sprung trap keeps reporting what it actually paid.
     */
    public function effectiveValueSecondsAt(\DateTimeImmutable $at): int
    {
        return match ($this->status) {
            TimeTrapStatus::Armed => $this->valueSecondsAt($this->clampedToRoundEnd($at)),
            TimeTrapStatus::Pending => $this->frozenValueSeconds ?? 0,
            TimeTrapStatus::Sprung => $this->awardedSeconds ?? 0,
        };
    }

    /** An armed trap stops accruing when the round stops: past that its minutes are unreachable. */
    private function clampedToRoundEnd(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $endedAt = $this->round->getSeekingEndedAt();

        return $endedAt !== null && $endedAt < $at ? $endedAt : $at;
    }
}
