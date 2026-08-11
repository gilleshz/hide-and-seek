<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class LocationInput
{
    #[Assert\NotNull]
    #[Assert\Range(min: -90, max: 90)]
    #[Groups([Group::LOCATION_WRITE])]
    public float $lat = 0.0;

    #[Assert\NotNull]
    #[Assert\Range(min: -180, max: 180)]
    #[Groups([Group::LOCATION_WRITE])]
    public float $lng = 0.0;

    #[Groups([Group::LOCATION_WRITE])]
    public ?float $altitude = null;
}
