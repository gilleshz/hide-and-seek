<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class TransitLinePreviewInput
{
    /**
     * Overpass returns full member geometry: a whole city's network would be tens of megabytes.
     * The cap keeps the preview readable on a small map.
     *
     * @var list<string>
     */
    #[Assert\Count(min: 1, max: 250)]
    #[Groups([Group::TRANSIT_LINE_PREVIEW_WRITE])]
    public array $osmIds = [];
}
