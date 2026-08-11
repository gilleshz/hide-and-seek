<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\DateFormat;
use App\Entity\Game;
use App\Serializer\Group;
use App\State\ChatImageUploadProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'ChatImage',
    operations: [
        new Post(
            uriTemplate: '/games/{gameKey}/chat/image',
            uriVariables: [
                'gameKey' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            input: false,
            processor: ChatImageUploadProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::CHAT_READ]],
    denormalizationContext: ['groups' => []],
)]
final class ChatImageUploadResource
{
    #[Groups([Group::CHAT_READ])]
    public string $uuid;

    #[Groups([Group::CHAT_READ])]
    public ?string $senderUuid = null;

    #[Groups([Group::CHAT_READ])]
    public string $type;

    #[Groups([Group::CHAT_READ])]
    public ?string $body = null;

    #[Groups([Group::CHAT_READ])]
    public ?string $imageRef = null;

    #[Groups([Group::CHAT_READ])]
    public ?string $replyToUuid = null;

    #[Groups([Group::CHAT_READ])]
    public string $createdAt;

    public static function fromMessage(\App\Entity\ChatMessage $message): self
    {
        $self = new self();
        $self->uuid = $message->getUuid();
        $self->senderUuid = $message->getSenderUuid();
        $self->type = $message->getType()->value;
        $self->body = $message->getBody();
        $self->imageRef = $message->getImageRef();
        $self->replyToUuid = $message->getReplyToUuid();
        $self->createdAt = $message->getCreatedAt()->format(DateFormat::ISO8601_UTC);

        return $self;
    }
}
