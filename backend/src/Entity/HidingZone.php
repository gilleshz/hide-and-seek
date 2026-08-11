<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HidingZoneRepository;
use Doctrine\ORM\Mapping as ORM;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: HidingZoneRepository::class)]
#[ORM\Table(name: 'hiding_zones')]
class HidingZone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Round::class)]
    #[ORM\JoinColumn(name: 'round_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Round $round;

    #[ORM\Column(type: 'geography_point')]
    private Point $stationPoint;

    #[ORM\Column(type: 'float')]
    private float $radiusMeters;

    /**
     * The station the hider designated, kept so Move can name the station being abandoned without
     * guessing which of the game's stations the point meant. Null when the overlay shows no station.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $stationName = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Round $round, Point $stationPoint, float $radiusMeters)
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->round = $round;
        $this->stationPoint = $stationPoint;
        $this->radiusMeters = $radiusMeters;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function getStationPoint(): Point
    {
        return $this->stationPoint;
    }

    public function setStationPoint(Point $stationPoint): self
    {
        $this->stationPoint = $stationPoint;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getRadiusMeters(): float
    {
        return $this->radiusMeters;
    }

    public function setRadiusMeters(float $radiusMeters): self
    {
        $this->radiusMeters = $radiusMeters;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getStationName(): ?string
    {
        return $this->stationName;
    }

    public function setStationName(?string $stationName): self
    {
        $this->stationName = $stationName;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
