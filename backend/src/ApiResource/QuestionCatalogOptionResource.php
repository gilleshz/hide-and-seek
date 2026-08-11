<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\QuestionCatalog\CatalogOption;
use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;

final class QuestionCatalogOptionResource
{
    #[Groups([Group::QUESTION_CATALOG_READ])]
    public string $label;

    #[Groups([Group::QUESTION_CATALOG_READ])]
    public ?string $featureType;

    #[Groups([Group::QUESTION_CATALOG_READ])]
    public ?float $meters;

    #[Groups([Group::QUESTION_CATALOG_READ])]
    public string $minSize;

    #[Groups([Group::QUESTION_CATALOG_READ])]
    public bool $transitLine;

    #[Groups([Group::QUESTION_CATALOG_READ])]
    public bool $stationNameLength = false;

    #[Groups([Group::QUESTION_CATALOG_READ])]
    public bool $seaLevel = false;

    public static function fromOption(CatalogOption $option): self
    {
        $self = new self();
        $self->label = $option->label;
        $self->featureType = $option->featureType?->value;
        $self->meters = $option->meters;
        $self->minSize = $option->minSize->value;
        $self->transitLine = $option->transitLine;
        $self->stationNameLength = $option->stationNameLength;
        $self->seaLevel = $option->seaLevel;

        return $self;
    }
}
