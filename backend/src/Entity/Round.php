<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\ScoreBonus;
use App\Enum\RoundStatus;
use App\Repository\RoundRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RoundRepository::class)]
#[ORM\Table(name: 'rounds')]
class Round
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Game::class)]
    #[ORM\JoinColumn(name: 'game_id', nullable: false, onDelete: 'CASCADE')]
    private Game $game;

    #[ORM\Column(type: 'string', length: 16, enumType: RoundStatus::class)]
    private RoundStatus $status;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $hidingPeriodStartedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $hidingPeriodEndsAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $seekingEndedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endgameStartedAt = null;

    /**
     * Seeking time already earned before a Move paused the clock. Hiding time is this plus the time
     * since the current hidingPeriodEndsAt, so a Move costs the hiders nothing they had banked.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $bankedSeekingSeconds = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $inMovePeriod = false;

    /** Flat time-bonus minutes the hiders declared when they stopped the round. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $bonusMinutes = 0;

    /** Percentage-bonus points declared at the same time, each applied to the raw hiding time. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $bonusPercent = 0;

    /** Server-computed from the traps the seekers actually sprung, so no hider can declare it. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $trapBonusSeconds = 0;

    /** Only a hider-declared stop scores; a lobby abort ends the round without one. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $caught = false;

    /**
     * Snapshotted because a player who leaves the game has their RoundMembership rows deleted, which
     * would otherwise rewrite the hiding team of rounds already played.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $hiderNames = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Game $game)
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->game = $game;
        $this->status = RoundStatus::Lobby;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getGame(): Game
    {
        return $this->game;
    }

    public function getStatus(): RoundStatus
    {
        return $this->status;
    }

    public function setStatus(RoundStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getHidingPeriodStartedAt(): ?\DateTimeImmutable
    {
        return $this->hidingPeriodStartedAt;
    }

    public function setHidingPeriodStartedAt(?\DateTimeImmutable $hidingPeriodStartedAt): self
    {
        $this->hidingPeriodStartedAt = $hidingPeriodStartedAt;

        return $this;
    }

    public function getHidingPeriodEndsAt(): ?\DateTimeImmutable
    {
        return $this->hidingPeriodEndsAt;
    }

    public function setHidingPeriodEndsAt(?\DateTimeImmutable $hidingPeriodEndsAt): self
    {
        $this->hidingPeriodEndsAt = $hidingPeriodEndsAt;

        return $this;
    }

    public function getSeekingEndedAt(): ?\DateTimeImmutable
    {
        return $this->seekingEndedAt;
    }

    public function setSeekingEndedAt(?\DateTimeImmutable $seekingEndedAt): self
    {
        $this->seekingEndedAt = $seekingEndedAt;

        return $this;
    }

    public function getEndgameStartedAt(): ?\DateTimeImmutable
    {
        return $this->endgameStartedAt;
    }

    public function setEndgameStartedAt(?\DateTimeImmutable $endgameStartedAt): self
    {
        $this->endgameStartedAt = $endgameStartedAt;

        return $this;
    }

    public function getBankedSeekingSeconds(): int
    {
        return $this->bankedSeekingSeconds;
    }

    public function setBankedSeekingSeconds(int $bankedSeekingSeconds): self
    {
        $this->bankedSeekingSeconds = $bankedSeekingSeconds;

        return $this;
    }

    public function isInMovePeriod(): bool
    {
        return $this->inMovePeriod;
    }

    public function setInMovePeriod(bool $inMovePeriod): self
    {
        $this->inMovePeriod = $inMovePeriod;

        return $this;
    }

    public function getBonusMinutes(): int
    {
        return $this->bonusMinutes;
    }

    public function getBonusPercent(): int
    {
        return $this->bonusPercent;
    }

    public function setBonus(ScoreBonus $bonus): self
    {
        $this->bonusMinutes = $bonus->minutes;
        $this->bonusPercent = $bonus->percent;

        return $this;
    }

    public function getBonus(): ScoreBonus
    {
        return new ScoreBonus($this->bonusMinutes, $this->bonusPercent);
    }

    public function getTrapBonusSeconds(): int
    {
        return $this->trapBonusSeconds;
    }

    public function addTrapBonusSeconds(int $seconds): self
    {
        $this->trapBonusSeconds += $seconds;

        return $this;
    }

    public function isCaught(): bool
    {
        return $this->caught;
    }

    public function setCaught(bool $caught): self
    {
        $this->caught = $caught;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getHiderNames(): ?array
    {
        return $this->hiderNames;
    }

    /**
     * @param list<string>|null $hiderNames
     */
    public function setHiderNames(?array $hiderNames): self
    {
        $this->hiderNames = $hiderNames;

        return $this;
    }

    public function getHidingTimeSeconds(): ?int
    {
        $endsAt = $this->hidingPeriodEndsAt;
        $endedAt = $this->seekingEndedAt;
        if ($this->status !== RoundStatus::Ended || $endsAt === null || $endedAt === null) {
            return null;
        }

        return $this->bankedSeekingSeconds + $endedAt->getTimestamp() - $endsAt->getTimestamp();
    }

    /** hidingTimeSeconds is the raw run; this is what the bonus cards turned it into. */
    public function getScoreSeconds(): ?int
    {
        $raw = $this->getHidingTimeSeconds();
        if ($raw === null) {
            return null;
        }

        // A percentage bonus is a share of the raw hiding time only, so a sprung trap sits outside it.
        return $raw + $this->trapBonusSeconds + $this->getBonus()->secondsFor($raw);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
