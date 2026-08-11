<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\FeatureType;
use App\Enum\PhotoTarget;
use App\Enum\QuestionCategory;
use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class AskQuestionInput
{
    #[Assert\NotNull]
    #[Groups([Group::QUESTION_WRITE])]
    public QuestionCategory $category = QuestionCategory::Radar;

    #[Assert\Positive]
    #[Groups([Group::QUESTION_WRITE])]
    public ?float $radiusMeters = null;

    #[Assert\Range(min: -90, max: 90)]
    #[Groups([Group::QUESTION_WRITE])]
    public ?float $seekerLat = null;

    #[Assert\Range(min: -180, max: 180)]
    #[Groups([Group::QUESTION_WRITE])]
    public ?float $seekerLng = null;

    #[Assert\Range(min: -90, max: 90)]
    #[Groups([Group::QUESTION_WRITE])]
    public ?float $startLat = null;

    #[Assert\Range(min: -180, max: 180)]
    #[Groups([Group::QUESTION_WRITE])]
    public ?float $startLng = null;

    #[Assert\Positive]
    #[Groups([Group::QUESTION_WRITE])]
    public ?float $distanceMeters = null;

    #[Groups([Group::QUESTION_WRITE])]
    public ?FeatureType $featureType = null;

    #[Assert\Positive]
    #[Groups([Group::QUESTION_WRITE])]
    public ?float $withinMeters = null;

    #[Groups([Group::QUESTION_WRITE])]
    public ?PhotoTarget $photoTarget = null;

    #[Groups([Group::QUESTION_WRITE])]
    public bool $isCustomRadius = false;

    #[Groups([Group::QUESTION_WRITE])]
    public ?string $transitLineOsmId = null;

    #[Groups([Group::QUESTION_WRITE])]
    public ?string $transitLineOsmType = null;

    #[Groups([Group::QUESTION_WRITE])]
    public ?bool $stationNameLength = null;

    #[Groups([Group::QUESTION_WRITE])]
    public ?bool $seaLevel = null;

    #[Groups([Group::QUESTION_WRITE])]
    public ?float $seekerAltitude = null;
}
