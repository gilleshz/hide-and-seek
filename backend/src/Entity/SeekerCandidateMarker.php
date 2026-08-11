<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeekerCandidateMarkerRepository;
use Doctrine\ORM\Mapping as ORM;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SeekerCandidateMarkerRepository::class)]
#[ORM\Table(name: 'seeker_candidate_markers')]
class SeekerCandidateMarker
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
    #[ORM\JoinColumn(name: 'player_id', nullable: false, onDelete: 'CASCADE')]
    private Player $player;

    #[ORM\Column(type: 'geography_point')]
    private Point $point;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Round $round, Player $player, Point $point)
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->round = $round;
        $this->player = $player;
        $this->point = $point;
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

    public function getRound(): Round
    {
        return $this->round;
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    public function getPoint(): Point
    {
        return $this->point;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
