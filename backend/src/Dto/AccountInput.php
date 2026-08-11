<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class AccountInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 80)]
    #[Groups([Group::ACCOUNT_WRITE])]
    public string $name = '';

    #[Assert\Length(min: 4, max: 64)]
    #[Groups([Group::ACCOUNT_WRITE])]
    public ?string $password = null;
}
