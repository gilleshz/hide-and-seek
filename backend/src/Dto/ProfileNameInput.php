<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class ProfileNameInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 80, normalizer: 'trim')]
    #[Groups([Group::PROFILE_WRITE])]
    public string $name = '';
}
