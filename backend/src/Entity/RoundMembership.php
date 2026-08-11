<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Side;
use App\Repository\RoundMembershipRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RoundMembershipRepository::class)]
#[ORM\Table(name: 'round_memberships')]
#[ORM\UniqueConstraint(name: 'uniq_membership_round_player', columns: ['round_id', 'player_id'])]
class RoundMembership
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

    #[ORM\Column(type: 'string', length: 16, enumType: Side::class)]
    private Side $side;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Round $round, Player $player, Side $side)
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->round = $round;
        $this->player = $player;
        $this->side = $side;
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

    public function getSide(): Side
    {
        return $this->side;
    }

    public function setSide(Side $side): self
    {
        $this->side = $side;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
