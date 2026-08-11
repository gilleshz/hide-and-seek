<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class TimeTrapResolutionInput
{
    #[Assert\NotNull]
    #[Groups([Group::TIME_TRAP_WRITE])]
    public bool $confirmed = false;
}
