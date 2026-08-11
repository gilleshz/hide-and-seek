<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\ChatMessageInput;
use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Enum\ChatMessageType;
use App\Serializer\Group;
use App\State\ChatMessageCollectionProvider;
use App\State\ChatMessageDeleteProcessor;
use App\State\ChatMessageProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'ChatMessage',
    operations: [
        new GetCollection(
            uriTemplate: '/games/{gameKey}/chat',
            uriVariables: [
                'gameKey' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            provider: ChatMessageCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/games/{gameKey}/chat',
            uriVariables: [
                'gameKey' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            input: ChatMessageInput::class,
            processor: ChatMessageProcessor::class,
        ),
        new Delete(
            uriTemplate: '/games/{gameKey}/chat/{uuid}',
            uriVariables: [
                'gameKey' => new Link(fromClass: Game::class, identifiers: ['uuid']),
                'uuid' => new Link(identifiers: ['uuid']),
            ],
            read: false,
            processor: ChatMessageDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::CHAT_READ]],
    denormalizationContext: ['groups' => [Group::CHAT_WRITE]],
)]
final class ChatMessageResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::CHAT_READ])]
    public string $uuid;

    #[Groups([Group::CHAT_READ])]
    public ?string $senderUuid;

    #[Groups([Group::CHAT_READ])]
    public ?string $senderName = null;

    #[Groups([Group::CHAT_READ])]
    public ChatMessageType $type;

    #[Groups([Group::CHAT_READ])]
    public ?string $body = null;

    #[Groups([Group::CHAT_READ])]
    public ?string $bodyKey = null;

    /** @var array<string, string|int>|null */
    #[Groups([Group::CHAT_READ])]
    public ?array $bodyArgs = null;

    #[Groups([Group::CHAT_READ])]
    public ?string $imageRef = null;

    #[Groups([Group::CHAT_READ])]
    public ?string $questionUuid = null;

    #[Groups([Group::CHAT_READ])]
    public ?string $replyToUuid = null;

    #[Groups([Group::CHAT_READ])]
    public \DateTimeImmutable $createdAt;

    #[Groups([Group::CHAT_READ])]
    public bool $deleted = false;

    public static function fromEntity(ChatMessage $message): self
    {
        $self = new self();
        $self->uuid = $message->getUuid();
        $self->senderUuid = $message->getSenderUuid();
        $self->senderName = $message->getSenderName();
        $self->type = $message->getType();
        $self->body = $message->getBody();
        $self->bodyKey = $message->getBodyKey();
        $self->bodyArgs = $message->getBodyArgs();
        $self->imageRef = $message->getImageRef();
        $self->questionUuid = $message->getQuestionUuid();
        $self->replyToUuid = $message->getReplyToUuid();
        $self->createdAt = $message->getCreatedAt();
        $self->deleted = $message->isDeleted();

        return $self;
    }
}
