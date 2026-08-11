<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Side;
use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;

final class TeamInput
{
    #[Groups([Group::TEAM_WRITE])]
    public Side $side = Side::Seeker;
}
