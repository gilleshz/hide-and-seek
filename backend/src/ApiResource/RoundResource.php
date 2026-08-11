<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\RoundStartInput;
use App\Dto\RoundStopInput;
use App\Entity\Game;
use App\Entity\Round;
use App\Enum\RoundStatus;
use App\Serializer\Group;
use App\State\RoundCreateProcessor;
use App\State\RoundProvider;
use App\State\RoundStartProcessor;
use App\State\RoundStopProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'Round',
    operations: [
        new Get(
            uriTemplate: '/rounds/{roundUuid}',
            uriVariables: [
                'roundUuid' => new Link(identifiers: ['roundUuid']),
            ],
            provider: RoundProvider::class,
        ),
        new Post(
            uriTemplate: '/rounds/{roundUuid}/start',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            denormalizationContext: ['groups' => [Group::ROUND_WRITE]],
            input: RoundStartInput::class,
            processor: RoundStartProcessor::class,
        ),
        new Post(
            uriTemplate: '/rounds/{roundUuid}/stop',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            denormalizationContext: ['groups' => [Group::ROUND_WRITE]],
            input: RoundStopInput::class,
            processor: RoundStopProcessor::class,
        ),
        new Post(
            uriTemplate: '/games/{gameUuid}/rounds',
            uriVariables: [
                'gameUuid' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            input: false,
            processor: RoundCreateProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::ROUND_READ]],
)]
final class RoundResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::ROUND_READ])]
    public string $roundUuid;

    #[Groups([Group::ROUND_READ])]
    public RoundStatus $status;

    #[Groups([Group::ROUND_READ])]
    public ?\DateTimeImmutable $hidingPeriodStartedAt;

    #[Groups([Group::ROUND_READ])]
    public ?\DateTimeImmutable $hidingPeriodEndsAt;

    #[Groups([Group::ROUND_READ])]
    public ?\DateTimeImmutable $seekingEndedAt;

    #[Groups([Group::ROUND_READ])]
    public ?int $hidingTimeSeconds;

    #[Groups([Group::ROUND_READ])]
    public ?float $hidingRadiusMeters;

    /**
     * hidingRadiusMeters falls back to the game default, so this flag says whether a zone was ever set.
     * Hiders use it to know they are on cards, not free placement; it carries no coordinate, so seekers may read it.
     */
    #[Groups([Group::ROUND_READ])]
    public bool $hasHidingZone = false;

    #[Groups([Group::ROUND_READ])]
    public int $bankedSeekingSeconds;

    #[Groups([Group::ROUND_READ])]
    public bool $inMovePeriod;

    #[Groups([Group::ROUND_READ])]
    public int $bonusMinutes;

    #[Groups([Group::ROUND_READ])]
    public int $bonusPercent;

    /** hidingTimeSeconds is the raw run; this is what the bonus cards turned it into. */
    #[Groups([Group::ROUND_READ])]
    public ?int $scoreSeconds;

    #[Groups([Group::ROUND_READ])]
    public bool $caught;

    public static function fromEntity(
        Round $round,
        ?float $hidingRadiusMeters = null,
        bool $hasHidingZone = false,
    ): self {
        $self = new self();
        $self->roundUuid = $round->getUuid();
        $self->status = $round->getStatus();
        $self->hidingPeriodStartedAt = $round->getHidingPeriodStartedAt();
        $self->hidingPeriodEndsAt = $round->getHidingPeriodEndsAt();
        $self->seekingEndedAt = $round->getSeekingEndedAt();
        $self->hidingTimeSeconds = $round->getHidingTimeSeconds();
        $self->hidingRadiusMeters = $hidingRadiusMeters;
        $self->hasHidingZone = $hasHidingZone;
        $self->bankedSeekingSeconds = $round->getBankedSeekingSeconds();
        $self->inMovePeriod = $round->isInMovePeriod();
        $self->bonusMinutes = $round->getBonusMinutes();
        $self->bonusPercent = $round->getBonusPercent();
        $self->scoreSeconds = $round->getScoreSeconds();
        $self->caught = $round->isCaught();

        return $self;
    }
}
