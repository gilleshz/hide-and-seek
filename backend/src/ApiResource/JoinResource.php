<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\JoinInput;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Serializer\Group;
use App\State\JoinProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'Join',
    operations: [
        new Post(
            uriTemplate: '/games/{gameKey}/join',
            uriVariables: [
                'gameKey' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            input: JoinInput::class,
            processor: JoinProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::JOIN_READ]],
    denormalizationContext: ['groups' => [Group::JOIN_WRITE]],
)]
final class JoinResource
{
    #[Groups([Group::JOIN_READ])]
    public string $playerUuid;

    #[Groups([Group::JOIN_READ])]
    public string $displayName;

    #[Groups([Group::JOIN_READ])]
    public string $gameUuid;

    #[Groups([Group::JOIN_READ])]
    public string $roundUuid;

    #[Groups([Group::JOIN_READ])]
    public string $mercureToken;

    /**
     * @var list<string>
     */
    #[Groups([Group::JOIN_READ])]
    public array $topics;

    /**
     * @param list<string> $topics
     */
    public static function create(
        Player $player,
        Game $game,
        Round $round,
        string $mercureToken,
        array $topics,
    ): self {
        $self = new self();
        $self->playerUuid = $player->getUuid();
        $self->displayName = $player->getDisplayName();
        $self->gameUuid = $game->getUuid();
        $self->roundUuid = $round->getUuid();
        $self->mercureToken = $mercureToken;
        $self->topics = $topics;

        return $self;
    }
}
