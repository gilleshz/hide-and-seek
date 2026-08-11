<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\TimeTrapResolutionInput;
use App\Entity\Round;
use App\Entity\TimeTrap;
use App\RoundTiming;
use App\Serializer\Group;
use App\State\TimeTrapCollectionProvider;
use App\State\TimeTrapPlacementProcessor;
use App\State\TimeTrapResolutionProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'TimeTrap',
    operations: [
        new GetCollection(
            uriTemplate: '/rounds/{roundUuid}/time-traps',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            provider: TimeTrapCollectionProvider::class,
        ),
        // Multipart, not JSON: the card photo is required by the server, not merely prompted for.
        new Post(
            uriTemplate: '/rounds/{roundUuid}/time-traps',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            input: false,
            processor: TimeTrapPlacementProcessor::class,
        ),
        new Post(
            uriTemplate: '/rounds/{roundUuid}/time-traps/{trapUuid}/resolution',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
                'trapUuid' => new Link(identifiers: ['uuid']),
            ],
            input: TimeTrapResolutionInput::class,
            processor: TimeTrapResolutionProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::TIME_TRAP_READ]],
    denormalizationContext: ['groups' => [Group::TIME_TRAP_WRITE]],
)]
final class TimeTrapResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::TIME_TRAP_READ])]
    public string $uuid;

    #[Groups([Group::TIME_TRAP_READ])]
    public string $roundUuid;

    #[Groups([Group::TIME_TRAP_READ])]
    public ?string $stationName = null;

    #[Groups([Group::TIME_TRAP_READ])]
    public float $lat;

    #[Groups([Group::TIME_TRAP_READ])]
    public float $lng;

    #[Groups([Group::TIME_TRAP_READ])]
    public \DateTimeImmutable $placedAt;

    #[Groups([Group::TIME_TRAP_READ])]
    public string $status;

    #[Groups([Group::TIME_TRAP_READ])]
    public int $valueSeconds;

    #[Groups([Group::TIME_TRAP_READ])]
    public int $intervalMinutes;

    #[Groups([Group::TIME_TRAP_READ])]
    public int $incrementMinutes;

    #[Groups([Group::TIME_TRAP_READ])]
    public ?\DateTimeImmutable $detectedAt = null;

    #[Groups([Group::TIME_TRAP_READ])]
    public ?string $detectedByName = null;

    #[Groups([Group::TIME_TRAP_READ])]
    public ?int $awardedSeconds = null;

    public static function fromEntity(TimeTrap $trap): self
    {
        $size = $trap->getRound()->getGame()->getSize();

        $self = new self();
        $self->uuid = $trap->getUuid();
        $self->roundUuid = $trap->getRound()->getUuid();
        $self->stationName = $trap->getStationName();
        $self->lat = $trap->getPoint()->getLatitude();
        $self->lng = $trap->getPoint()->getLongitude();
        $self->placedAt = $trap->getPlacedAt();
        $self->status = $trap->getStatus()->value;
        $self->valueSeconds = $trap->effectiveValueSecondsAt(new \DateTimeImmutable());
        $self->intervalMinutes = RoundTiming::timeTrapIntervalMinutes($size);
        $self->incrementMinutes = RoundTiming::timeTrapIncrementMinutes($size);
        $self->detectedAt = $trap->getDetectedAt();
        $self->detectedByName = $trap->getDetectedByPlayer()?->getDisplayName();
        $self->awardedSeconds = $trap->getAwardedSeconds();

        return $self;
    }
}
