<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StreetNetworkStatus;
use App\Repository\RoundStreetNetworkRepository;
use Doctrine\ORM\Mapping as ORM;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RoundStreetNetworkRepository::class)]
#[ORM\Table(name: 'round_street_networks')]
#[ORM\Index(name: 'idx_round_street_network_status', columns: ['status'])]
class RoundStreetNetwork
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
    private Point $center;

    #[ORM\Column(type: 'float')]
    private float $radiusMeters;

    #[ORM\Column(type: 'string', length: 16, enumType: StreetNetworkStatus::class)]
    private StreetNetworkStatus $status = StreetNetworkStatus::Pending;

    /**
     * The way list is jsonb rather than PostGIS geometry because nothing queries it spatially: it is
     * fetched whole, shipped to the hider's device and snapped there.
     *
     * @var list<array{
     *     class: string,
     *     coordinates: list<array{0: float, 1: float}>,
     *     junctionIndices: list<int>,
     * }>|null
     */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $payload = null;

    #[ORM\Column(type: 'integer')]
    private int $wayCount = 0;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $fetchedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Round $round, Point $center, float $radiusMeters)
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->round = $round;
        $this->center = $center;
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

    public function getCenter(): Point
    {
        return $this->center;
    }

    public function setCenter(Point $center): self
    {
        $this->center = $center;
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

    public function getStatus(): StreetNetworkStatus
    {
        return $this->status;
    }

    public function setStatus(StreetNetworkStatus $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * @return list<array{
     *     class: string,
     *     coordinates: list<array{0: float, 1: float}>,
     *     junctionIndices: list<int>,
     * }>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /**
     * @param list<array{
     *     class: string,
     *     coordinates: list<array{0: float, 1: float}>,
     *     junctionIndices: list<int>,
     * }>|null $payload
     */
    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getWayCount(): int
    {
        return $this->wayCount;
    }

    public function setWayCount(int $wayCount): self
    {
        $this->wayCount = $wayCount;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): self
    {
        $this->attempts = $attempts;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getFetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function setFetchedAt(?\DateTimeImmutable $fetchedAt): self
    {
        $this->fetchedAt = $fetchedAt;
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
