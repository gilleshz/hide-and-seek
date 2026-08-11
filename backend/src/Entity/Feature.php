<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeatureType;
use App\Repository\FeatureRepository;
use Doctrine\ORM\Mapping as ORM;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FeatureRepository::class)]
#[ORM\Table(name: 'features')]
#[ORM\Index(name: 'idx_feature_game_type', columns: ['game_id', 'feature_type'])]
class Feature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 32, enumType: FeatureType::class)]
    private FeatureType $featureType;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'geography_point')]
    private Point $point;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $geometry = null;

    #[ORM\ManyToOne(targetEntity: Game::class)]
    #[ORM\JoinColumn(name: 'game_id', nullable: false, onDelete: 'CASCADE')]
    private Game $game;

    public function __construct(
        Game $game,
        FeatureType $featureType,
        ?string $name,
        Point $point,
        ?string $geometry = null,
    ) {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->game = $game;
        $this->featureType = $featureType;
        $this->name = $name;
        $this->point = $point;
        $this->geometry = $geometry;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getFeatureType(): FeatureType
    {
        return $this->featureType;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getPoint(): Point
    {
        return $this->point;
    }

    public function getGeometry(): ?string
    {
        return $this->geometry;
    }

    public function getGame(): Game
    {
        return $this->game;
    }
}
