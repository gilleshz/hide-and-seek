<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\QuestionPreviewInput;
use App\Entity\Round;
use App\Serializer\Group;
use App\State\QuestionPreviewProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'QuestionPreview',
    operations: [
        new Post(
            uriTemplate: '/rounds/{roundUuid}/question-preview',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            input: QuestionPreviewInput::class,
            processor: QuestionPreviewProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::QUESTION_PREVIEW_READ]],
    denormalizationContext: ['groups' => [Group::QUESTION_PREVIEW_WRITE]],
)]
final class QuestionPreviewResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::QUESTION_PREVIEW_READ])]
    public string $id = 'preview';

    #[Groups([Group::QUESTION_PREVIEW_READ])]
    public ?string $constraintGeoJson = null;

    #[Groups([Group::QUESTION_PREVIEW_READ])]
    public ?string $currentPossibleAreaGeoJson = null;

    #[Groups([Group::QUESTION_PREVIEW_READ])]
    public ?string $projectedPossibleAreaGeoJson = null;

    #[Groups([Group::QUESTION_PREVIEW_READ])]
    public ?string $excludedPossibleAreaGeoJson = null;

    #[Groups([Group::QUESTION_PREVIEW_READ])]
    public float $currentAreaKm2 = 0.0;

    #[Groups([Group::QUESTION_PREVIEW_READ])]
    public float $projectedAreaKm2 = 0.0;

    /**
     * @param array{
     *     constraintGeoJson: ?string,
     *     currentAreaKm2: float,
     *     projectedAreaKm2: float,
     *     currentPossibleAreaGeoJson: ?string,
     *     projectedPossibleAreaGeoJson: ?string,
     *     excludedPossibleAreaGeoJson: ?string,
     * } $result
     */
    public static function fromResult(array $result): self
    {
        $self = new self();
        $self->constraintGeoJson = $result['constraintGeoJson'];
        $self->currentAreaKm2 = $result['currentAreaKm2'];
        $self->projectedAreaKm2 = $result['projectedAreaKm2'];
        $self->currentPossibleAreaGeoJson = $result['currentPossibleAreaGeoJson'];
        $self->projectedPossibleAreaGeoJson = $result['projectedPossibleAreaGeoJson'];
        $self->excludedPossibleAreaGeoJson = $result['excludedPossibleAreaGeoJson'];

        return $self;
    }
}
