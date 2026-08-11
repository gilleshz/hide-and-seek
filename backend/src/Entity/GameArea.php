<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameAreaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GameAreaRepository::class)]
#[ORM\Table(name: 'game_areas')]
class GameArea
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

    #[ORM\Column(type: 'string', length: 16)]
    private string $osmType;

    #[ORM\Column(type: 'bigint')]
    private int $osmId;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $adminLevel = null;

    #[ORM\Column(type: 'string', length: 200)]
    private string $name;

    public function __construct(Game $game, string $osmType, int $osmId, string $name, ?int $adminLevel)
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->game = $game;
        $this->osmType = $osmType;
        $this->osmId = $osmId;
        $this->name = $name;
        $this->adminLevel = $adminLevel;
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

    public function getOsmType(): string
    {
        return $this->osmType;
    }

    public function getOsmId(): int
    {
        return $this->osmId;
    }

    public function getAdminLevel(): ?int
    {
        return $this->adminLevel;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
