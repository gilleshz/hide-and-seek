<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\DateFormat;
use App\Dto\ChatReadInput;
use App\Entity\Game;
use App\Serializer\Group;
use App\State\ChatReadCursorCollectionProvider;
use App\State\ChatReadProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'ChatReadCursor',
    operations: [
        new GetCollection(
            uriTemplate: '/games/{gameKey}/chat/read-cursors',
            uriVariables: [
                'gameKey' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            provider: ChatReadCursorCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/games/{gameKey}/chat/read',
            uriVariables: [
                'gameKey' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            input: ChatReadInput::class,
            processor: ChatReadProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::CHAT_RECEIPT_READ]],
    denormalizationContext: ['groups' => [Group::CHAT_RECEIPT_WRITE]],
)]
final class ChatReadCursorResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::CHAT_RECEIPT_READ])]
    public string $playerUuid;

    #[Groups([Group::CHAT_RECEIPT_READ])]
    public string $playerName;

    #[Groups([Group::CHAT_RECEIPT_READ])]
    public ?string $readUpTo = null;

    public static function create(string $playerUuid, string $playerName, ?\DateTimeImmutable $readUpTo): self
    {
        $self = new self();
        $self->playerUuid = $playerUuid;
        $self->playerName = $playerName;
        $self->readUpTo = $readUpTo?->format(DateFormat::ISO8601_UTC);

        return $self;
    }
}
