<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\TeamInput;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Side;
use App\Serializer\Group;
use App\State\TeamProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'Team',
    operations: [
        new Post(
            uriTemplate: '/rounds/{roundUuid}/team',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            input: TeamInput::class,
            processor: TeamProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::TEAM_READ]],
    denormalizationContext: ['groups' => [Group::TEAM_WRITE]],
)]
final class TeamResource
{
    #[Groups([Group::TEAM_READ])]
    public string $playerUuid;

    #[Groups([Group::TEAM_READ])]
    public string $roundUuid;

    #[Groups([Group::TEAM_READ])]
    public Side $side;

    #[Groups([Group::TEAM_READ])]
    public string $mercureToken;

    /**
     * @var list<string>
     */
    #[Groups([Group::TEAM_READ])]
    public array $topics;

    /**
     * @param list<string> $topics
     */
    public static function create(RoundMembership $membership, string $mercureToken, array $topics): self
    {
        $self = new self();
        $self->playerUuid = $membership->getPlayer()->getUuid();
        $self->roundUuid = $membership->getRound()->getUuid();
        $self->side = $membership->getSide();
        $self->mercureToken = $mercureToken;
        $self->topics = $topics;

        return $self;
    }
}
