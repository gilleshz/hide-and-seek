<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\AskQuestionInput;
use App\Dto\CompleteThermometerInput;
use App\Entity\AskedQuestion;
use App\Entity\Round;
use App\Enum\FeatureType;
use App\Enum\MeasuringResult;
use App\Enum\PhotoTarget;
use App\Enum\QuestionCategory;
use App\Enum\ThermometerResult;
use App\Serializer\Group;
use App\State\AnswerPhotoQuestionProcessor;
use App\State\AskedQuestionCollectionProvider;
use App\State\AskedQuestionProcessor;
use App\State\AskedQuestionProvider;
use App\State\AskedQuestionRevealProcessor;
use App\State\CancelQuestionProcessor;
use App\State\CompleteThermometerProcessor;
use App\State\RandomizeQuestionProcessor;
use App\State\VetoQuestionProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'AskedQuestion',
    operations: [
        new Get(
            uriTemplate: '/questions/{questionUuid}',
            uriVariables: [
                'questionUuid' => new Link(identifiers: ['uuid']),
            ],
            provider: AskedQuestionProvider::class,
        ),
        new GetCollection(
            uriTemplate: '/rounds/{roundUuid}/questions',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            provider: AskedQuestionCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/rounds/{roundUuid}/questions',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            input: AskQuestionInput::class,
            processor: AskedQuestionProcessor::class,
        ),
        new Post(
            uriTemplate: '/questions/{questionUuid}/reveal',
            uriVariables: [
                'questionUuid' => new Link(identifiers: ['uuid']),
            ],
            input: false,
            processor: AskedQuestionRevealProcessor::class,
        ),
        new Post(
            uriTemplate: '/questions/{questionUuid}/answer-photo',
            uriVariables: [
                'questionUuid' => new Link(identifiers: ['uuid']),
            ],
            input: false,
            processor: AnswerPhotoQuestionProcessor::class,
        ),
        new Post(
            uriTemplate: '/questions/{questionUuid}/complete',
            uriVariables: [
                'questionUuid' => new Link(identifiers: ['uuid']),
            ],
            input: CompleteThermometerInput::class,
            processor: CompleteThermometerProcessor::class,
        ),
        new Post(
            uriTemplate: '/questions/{questionUuid}/cancel',
            uriVariables: [
                'questionUuid' => new Link(identifiers: ['uuid']),
            ],
            input: false,
            processor: CancelQuestionProcessor::class,
        ),
        new Post(
            uriTemplate: '/questions/{questionUuid}/veto',
            uriVariables: [
                'questionUuid' => new Link(identifiers: ['uuid']),
            ],
            input: false,
            processor: VetoQuestionProcessor::class,
            status: 204,
        ),
        new Post(
            uriTemplate: '/questions/{questionUuid}/randomize',
            uriVariables: [
                'questionUuid' => new Link(identifiers: ['uuid']),
            ],
            input: false,
            processor: RandomizeQuestionProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::QUESTION_READ]],
    denormalizationContext: ['groups' => [Group::QUESTION_WRITE]],
)]
final class AskedQuestionResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::QUESTION_READ])]
    public string $uuid;

    #[Groups([Group::QUESTION_READ])]
    public string $roundUuid;

    #[Groups([Group::QUESTION_READ])]
    public QuestionCategory $category;

    #[Groups([Group::QUESTION_READ])]
    public \DateTimeImmutable $askedAt;

    #[Groups([Group::QUESTION_READ])]
    public ?\DateTimeImmutable $revealDeadlineAt;

    #[Groups([Group::QUESTION_READ])]
    public ?\DateTimeImmutable $revealedAt;

    #[Groups([Group::QUESTION_READ])]
    public ?FeatureType $featureType;

    #[Groups([Group::QUESTION_READ])]
    public ?float $withinMeters;

    #[Groups([Group::QUESTION_READ])]
    public ?float $radiusMeters;

    #[Groups([Group::QUESTION_READ])]
    public ?float $distanceMeters;

    #[Groups([Group::QUESTION_READ])]
    public ?float $seekerLat;

    #[Groups([Group::QUESTION_READ])]
    public ?float $seekerLng;

    #[Groups([Group::QUESTION_READ])]
    public ?float $startLat;

    #[Groups([Group::QUESTION_READ])]
    public ?float $startLng;

    #[Groups([Group::QUESTION_READ])]
    public ?float $endLat;

    #[Groups([Group::QUESTION_READ])]
    public ?float $endLng;

    #[Groups([Group::QUESTION_READ])]
    public ?PhotoTarget $photoTarget;

    #[Groups([Group::QUESTION_READ])]
    public ?bool $radarAnswer;

    #[Groups([Group::QUESTION_READ])]
    public ?ThermometerResult $thermometerAnswer;

    #[Groups([Group::QUESTION_READ])]
    public ?bool $matchingAnswer;

    #[Groups([Group::QUESTION_READ])]
    public ?MeasuringResult $measuringAnswer;

    #[Groups([Group::QUESTION_READ])]
    public ?string $tentaclesAnswer;

    #[Groups([Group::QUESTION_READ])]
    public string $status;

    #[Groups([Group::QUESTION_READ])]
    public bool $isCustomRadius = false;

    #[Groups([Group::QUESTION_READ])]
    public int $repeatCount;

    #[Groups([Group::QUESTION_READ])]
    public ?string $transitLineLabel;

    #[Groups([Group::QUESTION_READ])]
    public bool $seaLevel = false;

    public static function fromEntity(AskedQuestion $question): self
    {
        $revealed = $question->getRevealedAt() !== null;

        $self = new self();
        $self->uuid = $question->getUuid();
        $self->roundUuid = $question->getRound()->getUuid();
        $self->category = $question->getCategory();
        $self->askedAt = $question->getAskedAt();
        $self->revealDeadlineAt = $question->getRevealDeadlineAt();
        $self->revealedAt = $question->getRevealedAt();
        $self->featureType = $question->getFeatureType();
        $self->withinMeters = $question->getWithinMeters();
        $self->radiusMeters = $question->getRadiusMeters();
        $self->distanceMeters = $question->getDistanceMeters();
        $self->seekerLat = $question->getSeekerPoint()?->getLatitude();
        $self->seekerLng = $question->getSeekerPoint()?->getLongitude();
        $self->startLat = $question->getStartPoint()?->getLatitude();
        $self->startLng = $question->getStartPoint()?->getLongitude();
        $self->endLat = $question->getEndPoint()?->getLatitude();
        $self->endLng = $question->getEndPoint()?->getLongitude();
        $self->photoTarget = $question->getPhotoTarget();
        $self->radarAnswer = $revealed ? $question->getRadarAnswer() : null;
        $self->thermometerAnswer = $revealed ? $question->getThermometerAnswer() : null;
        $self->matchingAnswer = $revealed ? $question->getMatchingAnswer() : null;
        $self->measuringAnswer = $revealed ? $question->getMeasuringAnswer() : null;
        $self->tentaclesAnswer = $revealed ? $question->getTentaclesAnswer() : null;
        $self->status = $question->getStatus()->value;
        $self->isCustomRadius = $question->isCustomRadius();
        $self->repeatCount = $question->getRepeatCount();
        $self->transitLineLabel = $question->getTransitLineLabel();
        $self->seaLevel = $question->isSeaLevel();

        return $self;
    }
}
