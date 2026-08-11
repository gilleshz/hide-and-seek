<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Edition;
use App\Enum\GameSize;
use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class GameConfigInput
{
    #[Assert\Length(max: 120)]
    #[Groups([Group::GAME_WRITE])]
    public ?string $name = null;

    #[Groups([Group::GAME_WRITE])]
    public ?GameSize $size = null;

    #[Groups([Group::GAME_WRITE])]
    public ?Edition $edition = null;
}
