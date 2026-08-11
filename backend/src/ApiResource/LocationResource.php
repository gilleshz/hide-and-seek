<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\LocationInput;
use App\Dto\LocationPingResult;
use App\Entity\Round;
use App\Serializer\Group;
use App\State\LocationProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'Location',
    operations: [
        new Post(
            uriTemplate: '/rounds/{roundUuid}/location',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            input: LocationInput::class,
            processor: LocationProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::LOCATION_READ]],
    denormalizationContext: ['groups' => [Group::LOCATION_WRITE]],
)]
final class LocationResource
{
    #[Groups([Group::LOCATION_READ])]
    public string $playerUuid;

    #[Groups([Group::LOCATION_READ])]
    public string $roundUuid;

    #[Groups([Group::LOCATION_READ])]
    public \DateTimeImmutable $recordedAt;

    /** True only when this very ingest started the round's endgame; false otherwise. */
    #[Groups([Group::LOCATION_READ])]
    public bool $endgame = false;

    public static function fromPing(LocationPingResult $result): self
    {
        $location = $result->location;
        $self = new self();
        $self->playerUuid = $location->getPlayer()->getUuid();
        $self->roundUuid = $location->getRound()->getUuid();
        $self->recordedAt = $location->getRecordedAt();
        $self->endgame = $result->endgameTriggered;

        return $self;
    }
}
