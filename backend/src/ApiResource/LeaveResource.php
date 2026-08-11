<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Entity\Game;
use App\Serializer\Group;
use App\State\LeaveProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'Leave',
    operations: [
        new Post(
            uriTemplate: '/games/{gameUuid}/leave',
            uriVariables: [
                'gameUuid' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            input: false,
            processor: LeaveProcessor::class,
            status: 204,
        ),
    ],
    normalizationContext: ['groups' => [Group::LEAVE_READ]],
    denormalizationContext: ['groups' => [Group::LEAVE_WRITE]],
)]
final class LeaveResource
{
    #[Groups([Group::LEAVE_READ])]
    public string $gameUuid;

    #[Groups([Group::LEAVE_READ])]
    public string $playerUuid;

    #[Groups([Group::LEAVE_READ])]
    public bool $removed;

    public static function removed(string $gameUuid, string $playerUuid): self
    {
        $self = new self();
        $self->gameUuid = $gameUuid;
        $self->playerUuid = $playerUuid;
        $self->removed = true;

        return $self;
    }
}
