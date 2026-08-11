<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeatureType;
use App\Enum\MeasuringResult;
use App\Enum\PhotoTarget;
use App\Enum\QuestionCategory;
use App\Enum\QuestionStatus;
use App\Enum\ThermometerResult;
use App\Repository\AskedQuestionRepository;
use Doctrine\ORM\Mapping as ORM;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AskedQuestionRepository::class)]
#[ORM\Table(name: 'asked_questions')]
#[ORM\Index(name: 'idx_question_round', columns: ['round_id'])]
class AskedQuestion
{
    public const string TENTACLES_NOT_WITHIN_REACH = 'Not within reach';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Round::class)]
    #[ORM\JoinColumn(name: 'round_id', nullable: false, onDelete: 'CASCADE')]
    private Round $round;

    #[ORM\Column(type: 'string', length: 16, enumType: QuestionCategory::class)]
    private QuestionCategory $category;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    #[ORM\JoinColumn(name: 'asker_player_id', nullable: false, onDelete: 'CASCADE')]
    private Player $askerPlayer;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $radiusMeters = null;

    #[ORM\Column(type: 'geography_point', nullable: true)]
    private ?Point $seekerPoint = null;

    #[ORM\Column(type: 'geography_point', nullable: true)]
    private ?Point $startPoint = null;

    #[ORM\Column(type: 'geography_point', nullable: true)]
    private ?Point $endPoint = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $distanceMeters = null;

    #[ORM\Column(type: 'string', length: 32, enumType: FeatureType::class, nullable: true)]
    private ?FeatureType $featureType = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $withinMeters = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $radarAnswer = null;

    #[ORM\Column(type: 'string', length: 16, enumType: ThermometerResult::class, nullable: true)]
    private ?ThermometerResult $thermometerAnswer = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $matchingAnswer = null;

    #[ORM\Column(type: 'string', length: 16, enumType: MeasuringResult::class, nullable: true)]
    private ?MeasuringResult $measuringAnswer = null;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $tentaclesAnswer = null;

    #[ORM\Column(name: 'photo_target', type: 'string', length: 40, enumType: PhotoTarget::class, nullable: true)]
    private ?PhotoTarget $photoTarget = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $askedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revealDeadlineAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revealedAt = null;

    #[ORM\Column(type: 'string', length: 16, enumType: QuestionStatus::class)]
    private QuestionStatus $status = QuestionStatus::Open;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $replacedByUuid = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $replacedQuestionUuid = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isCustomRadius = false;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $transitLineUuid = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $transitLineLabel = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $stationNameLength = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $seaLevel = false;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $seekerAltitude = null;

    #[ORM\Column(type: 'integer')]
    private int $repeatCount = 1;

    #[ORM\Column(type: 'integer')]
    #[ORM\Version]
    private int $version = 1;

    public function __construct(
        Round $round,
        Player $askerPlayer,
        QuestionCategory $category,
        ?\DateTimeImmutable $revealDeadlineAt,
    ) {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->round = $round;
        $this->askerPlayer = $askerPlayer;
        $this->category = $category;
        $this->revealDeadlineAt = $revealDeadlineAt;
        $this->askedAt = new \DateTimeImmutable();
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

    public function getCategory(): QuestionCategory
    {
        return $this->category;
    }

    public function getAskerPlayer(): Player
    {
        return $this->askerPlayer;
    }

    public function getRadiusMeters(): ?float
    {
        return $this->radiusMeters;
    }

    public function setRadiusMeters(?float $radiusMeters): self
    {
        $this->radiusMeters = $radiusMeters;

        return $this;
    }

    public function getSeekerPoint(): ?Point
    {
        return $this->seekerPoint;
    }

    public function setSeekerPoint(?Point $seekerPoint): self
    {
        $this->seekerPoint = $seekerPoint;

        return $this;
    }

    public function getStartPoint(): ?Point
    {
        return $this->startPoint;
    }

    public function setStartPoint(?Point $startPoint): self
    {
        $this->startPoint = $startPoint;

        return $this;
    }

    public function getEndPoint(): ?Point
    {
        return $this->endPoint;
    }

    public function setEndPoint(?Point $endPoint): self
    {
        $this->endPoint = $endPoint;

        return $this;
    }

    public function getDistanceMeters(): ?float
    {
        return $this->distanceMeters;
    }

    public function setDistanceMeters(?float $distanceMeters): self
    {
        $this->distanceMeters = $distanceMeters;

        return $this;
    }

    public function getRadarAnswer(): ?bool
    {
        return $this->radarAnswer;
    }

    public function setRadarAnswer(?bool $radarAnswer): self
    {
        $this->radarAnswer = $radarAnswer;

        return $this;
    }

    public function getThermometerAnswer(): ?ThermometerResult
    {
        return $this->thermometerAnswer;
    }

    public function setThermometerAnswer(?ThermometerResult $thermometerAnswer): self
    {
        $this->thermometerAnswer = $thermometerAnswer;

        return $this;
    }

    public function getFeatureType(): ?FeatureType
    {
        return $this->featureType;
    }

    public function setFeatureType(?FeatureType $featureType): self
    {
        $this->featureType = $featureType;

        return $this;
    }

    public function getWithinMeters(): ?float
    {
        return $this->withinMeters;
    }

    public function setWithinMeters(?float $withinMeters): self
    {
        $this->withinMeters = $withinMeters;

        return $this;
    }

    public function getMatchingAnswer(): ?bool
    {
        return $this->matchingAnswer;
    }

    public function setMatchingAnswer(?bool $matchingAnswer): self
    {
        $this->matchingAnswer = $matchingAnswer;

        return $this;
    }

    public function getMeasuringAnswer(): ?MeasuringResult
    {
        return $this->measuringAnswer;
    }

    public function setMeasuringAnswer(?MeasuringResult $measuringAnswer): self
    {
        $this->measuringAnswer = $measuringAnswer;

        return $this;
    }

    public function getTentaclesAnswer(): ?string
    {
        return $this->tentaclesAnswer;
    }

    public function setTentaclesAnswer(?string $tentaclesAnswer): self
    {
        $this->tentaclesAnswer = $tentaclesAnswer;

        return $this;
    }

    public function getAskedAt(): \DateTimeImmutable
    {
        return $this->askedAt;
    }

    public function getRevealDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->revealDeadlineAt;
    }

    public function setRevealDeadlineAt(?\DateTimeImmutable $revealDeadlineAt): self
    {
        $this->revealDeadlineAt = $revealDeadlineAt;

        return $this;
    }

    public function getPhotoTarget(): ?PhotoTarget
    {
        return $this->photoTarget;
    }

    public function setPhotoTarget(?PhotoTarget $photoTarget): self
    {
        $this->photoTarget = $photoTarget;

        return $this;
    }

    public function getRevealedAt(): ?\DateTimeImmutable
    {
        return $this->revealedAt;
    }

    public function setRevealedAt(?\DateTimeImmutable $revealedAt): self
    {
        $this->revealedAt = $revealedAt;

        return $this;
    }

    public function getStatus(): QuestionStatus
    {
        return $this->status;
    }

    public function setStatus(QuestionStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getReplacedByUuid(): ?string
    {
        return $this->replacedByUuid;
    }

    public function setReplacedByUuid(?string $replacedByUuid): self
    {
        $this->replacedByUuid = $replacedByUuid;

        return $this;
    }

    public function getReplacedQuestionUuid(): ?string
    {
        return $this->replacedQuestionUuid;
    }

    public function setReplacedQuestionUuid(?string $replacedQuestionUuid): self
    {
        $this->replacedQuestionUuid = $replacedQuestionUuid;

        return $this;
    }

    public function getRepeatCount(): int
    {
        return $this->repeatCount;
    }

    public function setRepeatCount(int $repeatCount): self
    {
        $this->repeatCount = $repeatCount;

        return $this;
    }

    public function isCustomRadius(): bool
    {
        return $this->isCustomRadius;
    }

    public function setIsCustomRadius(bool $isCustomRadius): self
    {
        $this->isCustomRadius = $isCustomRadius;

        return $this;
    }

    public function getTransitLineUuid(): ?string
    {
        return $this->transitLineUuid;
    }

    public function setTransitLineUuid(?string $transitLineUuid): self
    {
        $this->transitLineUuid = $transitLineUuid;

        return $this;
    }

    public function getTransitLineLabel(): ?string
    {
        return $this->transitLineLabel;
    }

    public function setTransitLineLabel(?string $transitLineLabel): self
    {
        $this->transitLineLabel = $transitLineLabel;

        return $this;
    }

    public function isStationNameLength(): bool
    {
        return $this->stationNameLength;
    }

    public function setStationNameLength(bool $stationNameLength): self
    {
        $this->stationNameLength = $stationNameLength;

        return $this;
    }

    public function isSeaLevel(): bool
    {
        return $this->seaLevel;
    }

    public function setSeaLevel(bool $seaLevel): self
    {
        $this->seaLevel = $seaLevel;

        return $this;
    }

    public function getSeekerAltitude(): ?float
    {
        return $this->seekerAltitude;
    }

    public function setSeekerAltitude(?float $seekerAltitude): self
    {
        $this->seekerAltitude = $seekerAltitude;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
