<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GtfsSourceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GtfsSourceRepository::class)]
#[ORM\Table(name: 'gtfs_source')]
class GtfsSource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Game::class)]
    #[ORM\JoinColumn(name: 'game_id', nullable: true, onDelete: 'SET NULL')]
    private ?Game $game = null;

    #[ORM\Column(type: 'string', length: 200)]
    private string $name;

    #[ORM\Column(type: 'string', length: 500)]
    private string $filePath;

    #[ORM\Column(type: 'string', length: 1000, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(type: 'integer')]
    private int $routeCount = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $filePath, ?string $uuid = null)
    {
        $this->uuid = $uuid ?? Uuid::v4()->toRfc4122();
        $this->name = $name;
        $this->filePath = $filePath;
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

    public function getGame(): ?Game
    {
        return $this->game;
    }

    public function setGame(?Game $game): self
    {
        $this->game = $game;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): self
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function getRouteCount(): int
    {
        return $this->routeCount;
    }

    public function setRouteCount(int $routeCount): self
    {
        $this->routeCount = $routeCount;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
