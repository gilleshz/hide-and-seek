<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;

final class GtfsSourceRoute
{
    #[Groups([Group::GTFS_SOURCE_READ])]
    public string $routeId;

    #[Groups([Group::GTFS_SOURCE_READ])]
    public string $shortName;

    #[Groups([Group::GTFS_SOURCE_READ])]
    public string $longName;

    #[Groups([Group::GTFS_SOURCE_READ])]
    public int $routeType;

    #[Groups([Group::GTFS_SOURCE_READ])]
    public string $color;

    #[Groups([Group::GTFS_SOURCE_READ])]
    public string $textColor;
}
