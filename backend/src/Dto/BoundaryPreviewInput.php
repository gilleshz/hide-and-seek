<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Edition;
use App\Enum\GameSize;
use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class BoundaryPreviewInput
{
    /** @var list<array<string, mixed>> The count is capped by NominatimService's lookup limit. */
    #[Groups([Group::BOUNDARY_PREVIEW_WRITE])]
    public array $areas = [];

    /** @var list<string> */
    #[Assert\Count(max: 250)]
    #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^\d{1,19}$/')])]
    #[Groups([Group::BOUNDARY_PREVIEW_WRITE])]
    public array $osmLineIds = [];

    /** @var list<array{gtfsSourceUuid: string, routeId: string}> */
    #[Assert\Count(max: 100)]
    #[Assert\All([
        new Assert\Collection(
            fields: [
                'gtfsSourceUuid' => new Assert\Uuid(),
                'routeId' => new Assert\Length(max: 200),
            ],
            allowExtraFields: true,
        ),
    ])]
    #[Groups([Group::BOUNDARY_PREVIEW_WRITE])]
    public array $gtfsLines = [];

    #[Groups([Group::BOUNDARY_PREVIEW_WRITE])]
    public GameSize $size = GameSize::Medium;

    #[Groups([Group::BOUNDARY_PREVIEW_WRITE])]
    public Edition $edition = Edition::Metric;
}
