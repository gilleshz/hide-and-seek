<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameTransitLineRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GameTransitLineRepository::class)]
#[ORM\Table(name: 'game_transit_lines')]
class GameTransitLine
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

    #[ORM\Column(type: 'string', length: 50)]
    private string $ref;

    #[ORM\Column(type: 'string', length: 200)]
    private string $name;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $colour = null;

    #[ORM\Column(type: 'string', length: 30)]
    private string $routeType;

    #[ORM\Column(type: 'string', length: 255)]
    private string $network;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $operator = null;

    public function __construct(
        Game $game,
        string $osmType,
        int $osmId,
        string $ref,
        string $name,
        string $routeType,
        string $network,
        ?string $colour,
        ?string $operator,
    ) {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->game = $game;
        $this->osmType = $osmType;
        $this->osmId = $osmId;
        $this->ref = $ref;
        $this->name = $name;
        $this->routeType = $routeType;
        $this->network = $network;
        $this->colour = $colour;
        $this->operator = $operator;
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

    public function getRef(): string
    {
        return $this->ref;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getColour(): ?string
    {
        return $this->colour;
    }

    public function getRouteType(): string
    {
        return $this->routeType;
    }

    public function getNetwork(): string
    {
        return $this->network;
    }

    public function getOperator(): ?string
    {
        return $this->operator;
    }
}
