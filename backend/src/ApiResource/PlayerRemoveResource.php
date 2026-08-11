<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Entity\Game;
use App\Entity\Player;
use App\Serializer\Group;
use App\State\PlayerRemoveProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'PlayerRemove',
    operations: [
        new Post(
            uriTemplate: '/games/{gameUuid}/players/{playerUuid}/remove',
            uriVariables: [
                'gameUuid' => new Link(fromClass: Game::class, identifiers: ['uuid']),
                'playerUuid' => new Link(fromClass: Player::class, identifiers: ['uuid']),
            ],
            input: false,
            processor: PlayerRemoveProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::PLAYER_REMOVE_READ]],
)]
final class PlayerRemoveResource
{
    #[Groups([Group::PLAYER_REMOVE_READ])]
    public string $playerUuid;

    #[Groups([Group::PLAYER_REMOVE_READ])]
    public string $displayName;

    public static function fromPlayer(Player $player): self
    {
        $self = new self();
        $self->playerUuid = $player->getUuid();
        $self->displayName = $player->getDisplayName();

        return $self;
    }
}
