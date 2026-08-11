<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class RoundStartInput
{
    #[Assert\Range(min: 1, max: 1440)]
    #[Groups([Group::ROUND_WRITE])]
    public ?int $hidingPeriodMinutes = null;
}
