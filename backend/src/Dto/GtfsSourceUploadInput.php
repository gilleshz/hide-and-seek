<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class GtfsSourceUploadInput
{
    #[Groups([Group::GTFS_SOURCE_WRITE])]
    public ?string $url = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    #[Groups([Group::GTFS_SOURCE_WRITE])]
    public string $name = '';
}
