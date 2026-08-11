<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class JoinInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 80)]
    #[Groups([Group::JOIN_WRITE])]
    public string $name = '';

    /** Required for every join, not just reconnects: the player's chosen password. */
    #[Assert\Length(min: 4, max: 64)]
    #[Groups([Group::JOIN_WRITE])]
    public ?string $password = null;
}
