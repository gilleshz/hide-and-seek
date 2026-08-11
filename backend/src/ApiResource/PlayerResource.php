<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Entity\Game;
use App\Entity\Player;
use App\Enum\Side;
use App\Serializer\Group;
use App\State\PlayerCollectionProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'Player',
    operations: [
        new GetCollection(
            uriTemplate: '/games/{gameKey}/players',
            uriVariables: [
                'gameKey' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            provider: PlayerCollectionProvider::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::PLAYER_READ]],
)]
final class PlayerResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::PLAYER_READ])]
    public string $uuid;

    #[Groups([Group::PLAYER_READ])]
    public string $displayName;

    #[Groups([Group::PLAYER_READ])]
    public ?Side $side;

    public static function fromEntity(Player $player, ?Side $side): self
    {
        $self = new self();
        $self->uuid = $player->getUuid();
        $self->displayName = $player->getDisplayName();
        $self->side = $side;

        return $self;
    }
}
