<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Entity\Game;
use App\Entity\Round;
use App\Serializer\Group;
use App\State\LeaderboardCollectionProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'LeaderboardEntry',
    operations: [
        new GetCollection(
            uriTemplate: '/games/{gameKey}/leaderboard',
            uriVariables: [
                'gameKey' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            provider: LeaderboardCollectionProvider::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::LEADERBOARD_READ]],
)]
final class LeaderboardEntryResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::LEADERBOARD_READ])]
    public string $roundUuid;

    #[Groups([Group::LEADERBOARD_READ])]
    public int $roundNumber;

    /** @var list<string> */
    #[Groups([Group::LEADERBOARD_READ])]
    public array $hiderNames;

    #[Groups([Group::LEADERBOARD_READ])]
    public int $hidingTimeSeconds;

    #[Groups([Group::LEADERBOARD_READ])]
    public int $scoreSeconds;

    #[Groups([Group::LEADERBOARD_READ])]
    public int $bonusMinutes;

    #[Groups([Group::LEADERBOARD_READ])]
    public int $bonusPercent;

    public static function fromEntity(Round $round, int $roundNumber): self
    {
        $self = new self();
        $self->roundUuid = $round->getUuid();
        $self->roundNumber = $roundNumber;
        $self->hiderNames = $round->getHiderNames() ?? [];
        $self->hidingTimeSeconds = $round->getHidingTimeSeconds() ?? 0;
        $self->scoreSeconds = $round->getScoreSeconds() ?? 0;
        $self->bonusMinutes = $round->getBonusMinutes();
        $self->bonusPercent = $round->getBonusPercent();

        return $self;
    }
}
