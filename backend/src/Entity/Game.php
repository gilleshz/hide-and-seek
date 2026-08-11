<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Edition;
use App\Enum\GameSize;
use App\Repository\GameRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GameRepository::class)]
#[ORM\Table(name: 'games')]
class Game
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(type: 'string', length: 1, enumType: GameSize::class)]
    private GameSize $size;

    #[ORM\Column(type: 'string', length: 16, enumType: Edition::class)]
    private Edition $edition;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $boundarySwLat = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $boundarySwLng = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $boundaryNeLat = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $boundaryNeLng = null;

    #[ORM\Column(type: 'string', length: 6, unique: true, nullable: true)]
    private ?string $joinCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $boundaryGeoJson = null;

    /** @var array<int, int>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $adminLevels = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $transitTilesPath = null;

    /**
     * The possible-area overlay reaches hiders unless the host opts out (SETUP-8: shared by default).
     */
    #[ORM\Column(name: 'possible_area_shared_with_hiders', type: 'boolean', options: ['default' => true])]
    private bool $possibleAreaSharedWithHiders = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, GameSize $size, Edition $edition)
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->name = $name;
        $this->size = $size;
        $this->edition = $edition;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getSize(): GameSize
    {
        return $this->size;
    }

    public function setSize(GameSize $size): self
    {
        $this->size = $size;
        $this->touch();

        return $this;
    }

    public function getEdition(): Edition
    {
        return $this->edition;
    }

    public function setEdition(Edition $edition): self
    {
        $this->edition = $edition;
        $this->touch();

        return $this;
    }

    public function isPossibleAreaSharedWithHiders(): bool
    {
        return $this->possibleAreaSharedWithHiders;
    }

    public function setPossibleAreaSharedWithHiders(bool $shared): self
    {
        $this->possibleAreaSharedWithHiders = $shared;
        $this->touch();

        return $this;
    }

    public function getJoinCode(): ?string
    {
        return $this->joinCode;
    }

    public function setJoinCode(string $joinCode): self
    {
        $this->joinCode = $joinCode;

        return $this;
    }

    public function getBoundarySwLat(): ?float
    {
        return $this->boundarySwLat;
    }

    public function getBoundarySwLng(): ?float
    {
        return $this->boundarySwLng;
    }

    public function getBoundaryNeLat(): ?float
    {
        return $this->boundaryNeLat;
    }

    public function getBoundaryNeLng(): ?float
    {
        return $this->boundaryNeLng;
    }

    public function setBoundary(?float $swLat, ?float $swLng, ?float $neLat, ?float $neLng): self
    {
        $this->boundarySwLat = $swLat;
        $this->boundarySwLng = $swLng;
        $this->boundaryNeLat = $neLat;
        $this->boundaryNeLng = $neLng;
        $this->touch();

        return $this;
    }

    public function getBoundaryGeoJson(): ?string
    {
        return $this->boundaryGeoJson;
    }

    public function setBoundaryGeoJson(?string $geoJson): self
    {
        $this->boundaryGeoJson = $geoJson;

        return $this;
    }

    /** @return array<int, int>|null */
    public function getAdminLevels(): ?array
    {
        return $this->adminLevels;
    }

    /** @param array<int, int>|null $adminLevels */
    public function setAdminLevels(?array $adminLevels): self
    {
        $this->adminLevels = $adminLevels;

        return $this;
    }

    public function getTransitTilesPath(): ?string
    {
        return $this->transitTilesPath;
    }

    public function setTransitTilesPath(?string $path): self
    {
        $this->transitTilesPath = $path;

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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
