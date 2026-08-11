<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class ChatReadInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Assert\Length(max: 36)]
    #[Groups([Group::CHAT_RECEIPT_WRITE])]
    public string $upToUuid = '';
}
