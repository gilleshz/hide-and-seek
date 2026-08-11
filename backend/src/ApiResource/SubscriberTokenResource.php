<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Entity\Round;
use App\Serializer\Group;
use App\State\SubscriberTokenProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'SubscriberToken',
    operations: [
        new Post(
            uriTemplate: '/rounds/{roundUuid}/subscriber-token',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            input: false,
            processor: SubscriberTokenProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::SUBSCRIBER_TOKEN_READ]],
)]
final class SubscriberTokenResource
{
    #[Groups([Group::SUBSCRIBER_TOKEN_READ])]
    public string $playerUuid;

    #[Groups([Group::SUBSCRIBER_TOKEN_READ])]
    public string $roundUuid;

    #[Groups([Group::SUBSCRIBER_TOKEN_READ])]
    public string $mercureToken;

    /**
     * @var list<string>
     */
    #[Groups([Group::SUBSCRIBER_TOKEN_READ])]
    public array $topics;

    /**
     * @param list<string> $topics
     */
    public static function create(string $playerUuid, string $roundUuid, string $mercureToken, array $topics): self
    {
        $self = new self();
        $self->playerUuid = $playerUuid;
        $self->roundUuid = $roundUuid;
        $self->mercureToken = $mercureToken;
        $self->topics = $topics;

        return $self;
    }
}
