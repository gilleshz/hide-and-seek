<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class ChatMessageInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 2000)]
    #[Groups([Group::CHAT_WRITE])]
    public string $body = '';

    #[Assert\Uuid]
    #[Assert\Length(max: 36)]
    #[Groups([Group::CHAT_WRITE])]
    public ?string $replyToUuid = null;
}
