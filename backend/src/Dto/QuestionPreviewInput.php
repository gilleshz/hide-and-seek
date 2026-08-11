<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class QuestionPreviewInput
{
    // Accepted but ignored: identity comes from the subscriber token; the app still sends it for backward compat.
    #[Assert\NotBlank]
    #[Groups([Group::QUESTION_PREVIEW_WRITE])]
    public string $askerPlayerUuid = '';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['radar', 'thermometer', 'measuring', 'matching', 'tentacles'])]
    #[Groups([Group::QUESTION_PREVIEW_WRITE])]
    public string $category;

    #[Assert\NotBlank]
    #[Assert\Range(min: -90, max: 90)]
    #[Groups([Group::QUESTION_PREVIEW_WRITE])]
    public float $seekerLat;

    #[Assert\NotBlank]
    #[Assert\Range(min: -180, max: 180)]
    #[Groups([Group::QUESTION_PREVIEW_WRITE])]
    public float $seekerLng;

    #[Groups([Group::QUESTION_PREVIEW_WRITE])]
    public ?float $endLat = null;

    #[Groups([Group::QUESTION_PREVIEW_WRITE])]
    public ?float $endLng = null;

    #[Assert\Positive]
    #[Groups([Group::QUESTION_PREVIEW_WRITE])]
    public ?int $radiusMeters = null;

    #[Groups([Group::QUESTION_PREVIEW_WRITE])]
    public ?string $featureType = null;

    #[Groups([Group::QUESTION_PREVIEW_WRITE])]
    public ?string $hypotheticalFeatureId = null;

    #[Groups([Group::QUESTION_PREVIEW_WRITE])]
    public ?int $withinMeters = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        'inside', 'outside',
        'hotter', 'colder',
        'closer', 'further',
        'same', 'different',
        'nearest', 'none',
    ])]
    #[Groups([Group::QUESTION_PREVIEW_WRITE])]
    public string $hypotheticalAnswer;
}
