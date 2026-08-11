<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameTransitStationRepository;
use Doctrine\ORM\Mapping as ORM;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GameTransitStationRepository::class)]
#[ORM\Table(name: 'game_transit_stations')]
#[ORM\Index(name: 'idx_transit_station_game', columns: ['game_id'])]
class GameTransitStation
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

    #[ORM\Column(type: 'string', length: 128)]
    private string $stationId;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'geography_point')]
    private Point $point;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $lineRefs;

    /** @param list<string> $lineRefs */
    public function __construct(
        Game $game,
        string $stationId,
        ?string $name,
        Point $point,
        array $lineRefs,
    ) {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->game = $game;
        $this->stationId = $stationId;
        $this->name = $name;
        $this->point = $point;
        $this->lineRefs = $lineRefs;
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

    public function getStationId(): string
    {
        return $this->stationId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getPoint(): Point
    {
        return $this->point;
    }

    /** @return list<string> */
    public function getLineRefs(): array
    {
        return $this->lineRefs;
    }
}
