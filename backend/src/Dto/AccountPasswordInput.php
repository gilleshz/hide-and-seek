<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class AccountPasswordInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 80)]
    #[Groups([Group::ACCOUNT_PASSWORD_WRITE])]
    public string $name = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 4, max: 64)]
    #[Groups([Group::ACCOUNT_PASSWORD_WRITE])]
    public string $currentPassword = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 4, max: 64)]
    #[Groups([Group::ACCOUNT_PASSWORD_WRITE])]
    public string $newPassword = '';
}
