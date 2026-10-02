<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class BoundaryPreviewInput
{
    /** @var list<array<string, mixed>> The count is capped by NominatimService's lookup limit. */
    #[Assert\Count(min: 1)]
    #[Groups([Group::BOUNDARY_PREVIEW_WRITE])]
    public array $areas = [];
}
