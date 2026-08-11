<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class TransitLineDiscoveryInput
{
    /** @var list<array<string, mixed>> */
    #[Assert\Count(min: 1, max: 12)]
    #[Groups([Group::TRANSIT_LINE_DISCOVERY_WRITE])]
    public array $areas = [];

    /** @var string[]|null */
    #[Assert\All([new Assert\Choice(choices: [
        'subway', 'light_rail', 'tram', 'train',
        'monorail', 'funicular', 'trolleybus', 'bus',
    ])])]
    #[Groups([Group::TRANSIT_LINE_DISCOVERY_WRITE])]
    public ?array $routeTypes = null;
}
