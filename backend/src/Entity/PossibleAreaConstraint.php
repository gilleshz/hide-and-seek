<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ConstraintMode;
use App\Enum\ConstraintSource;
use App\Repository\PossibleAreaConstraintRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PossibleAreaConstraintRepository::class)]
#[ORM\Table(name: 'possible_area_constraints')]
#[ORM\Index(name: 'idx_pac_round', columns: ['round_id'])]
class PossibleAreaConstraint
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

    /**
     * PostGIS geography polygon, stored as WKT.
     *
     * All inserts go through raw DBAL in the repository (ST_GeomFromText, ST_Buffer, etc.);
     * ORM save() only toggles enabled and never writes this column. Plain text instead of
     * columnDefinition avoids a second source of false-positive schema diffs.
     */
    #[ORM\Column(type: 'text')]
    private string $geometry;

    #[ORM\Column(type: 'string', length: 100)]
    private string $label;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $labelKey = null;

    /** @var array<string, string|int>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $labelArgs = null;

    // DB-level default is load-bearing: the raw DBAL INSERTs in the repository omit this column.
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    // DB-level default is load-bearing: the automated raw INSERTs omit this column, so it must default to proven.
    #[ORM\Column(type: 'string', length: 16, enumType: ConstraintSource::class, options: ['default' => 'proven'])]
    private ConstraintSource $source = ConstraintSource::Proven;

    #[ORM\Column(type: 'string', length: 16, nullable: true, enumType: ConstraintMode::class)]
    private ?ConstraintMode $mode = null;

    /**
     * Raw drawn polygon ring, stored as geography text via raw DBAL (like {@see $geometry}).
     * Manual excludes store the envelope complement in geometry, so the original ring is kept
     * here for display; ORM never writes it.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $drawGeometry = null;

    #[ORM\ManyToOne(targetEntity: RoundMembership::class)]
    #[ORM\JoinColumn(name: 'created_by_membership_id', nullable: true, onDelete: 'SET NULL')]
    private ?RoundMembership $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, string|int>|null $labelArgs
     */
    public function __construct(Round $round, string $geometryWkt, string $label, ?string $labelKey = null, ?array $labelArgs = null)
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->round = $round;
        $this->geometry = $geometryWkt;
        $this->label = $label;
        $this->labelKey = $labelKey;
        $this->labelArgs = $labelArgs;
        $this->enabled = true;
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

    public function getGeometry(): string
    {
        return $this->geometry;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getLabelKey(): ?string
    {
        return $this->labelKey;
    }

    public function setLabelKey(?string $labelKey): self
    {
        $this->labelKey = $labelKey;

        return $this;
    }

    /**
     * @return array<string, string|int>|null
     */
    public function getLabelArgs(): ?array
    {
        return $this->labelArgs;
    }

    /**
     * @param array<string, string|int>|null $labelArgs
     */
    public function setLabelArgs(?array $labelArgs): self
    {
        $this->labelArgs = $labelArgs;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getSource(): ConstraintSource
    {
        return $this->source;
    }

    public function getMode(): ?ConstraintMode
    {
        return $this->mode;
    }

    public function getDrawGeometry(): ?string
    {
        return $this->drawGeometry;
    }

    public function getCreatedBy(): ?RoundMembership
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
