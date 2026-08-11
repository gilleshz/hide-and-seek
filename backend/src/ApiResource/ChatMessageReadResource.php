<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\DateFormat;
use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Serializer\Group;
use App\State\ChatMessageReadCollectionProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'ChatMessageRead',
    operations: [
        new GetCollection(
            uriTemplate: '/games/{gameKey}/chat/{messageUuid}/reads',
            uriVariables: [
                'gameKey' => new Link(fromClass: Game::class, identifiers: ['uuid']),
                'messageUuid' => new Link(fromClass: ChatMessage::class, identifiers: ['uuid']),
            ],
            provider: ChatMessageReadCollectionProvider::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::CHAT_RECEIPT_READ]],
)]
final class ChatMessageReadResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::CHAT_RECEIPT_READ])]
    public string $playerUuid;

    #[Groups([Group::CHAT_RECEIPT_READ])]
    public string $playerName;

    #[Groups([Group::CHAT_RECEIPT_READ])]
    public string $readAt;

    public static function create(string $playerUuid, string $playerName, \DateTimeImmutable $readAt): self
    {
        $self = new self();
        $self->playerUuid = $playerUuid;
        $self->playerName = $playerName;
        $self->readAt = $readAt->format(DateFormat::ISO8601_UTC);

        return $self;
    }
}
