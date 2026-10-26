<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;

final class AreaFitArea
{
    public function __construct(
        #[Groups([Group::AREA_FIT_READ])]
        public readonly string $osmType,
        #[Groups([Group::AREA_FIT_READ])]
        public readonly string $osmId,
        #[Groups([Group::AREA_FIT_READ])]
        public readonly string $displayName,
        #[Groups([Group::AREA_FIT_READ])]
        public readonly int $adminLevel,
    ) {
    }
}
