<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\HidingZoneInput;
use App\Entity\HidingZone;
use App\Entity\Round;
use App\Serializer\Group;
use App\State\HidingZoneProcessor;
use App\State\HidingZoneProvider;
use App\State\ZoneCardProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'HidingZone',
    operations: [
        // Gated on the subscriber token: the one credential a seeker's app cannot hold.
        new Get(
            uriTemplate: '/rounds/{roundUuid}/zone',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            provider: HidingZoneProvider::class,
        ),
        new Post(
            uriTemplate: '/rounds/{roundUuid}/zone',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            input: HidingZoneInput::class,
            processor: HidingZoneProcessor::class,
        ),
        // Multipart, not JSON: the card photo is required by the server, not merely prompted for.
        new Post(
            uriTemplate: '/rounds/{roundUuid}/zone/card',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            input: false,
            processor: ZoneCardProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::ZONE_READ]],
    denormalizationContext: ['groups' => [Group::ZONE_WRITE]],
)]
final class HidingZoneResource
{
    #[Groups([Group::ZONE_READ])]
    public string $roundUuid;

    #[Groups([Group::ZONE_READ])]
    public float $lat;

    #[Groups([Group::ZONE_READ])]
    public float $lng;

    #[Groups([Group::ZONE_READ])]
    public float $radiusMeters;

    #[Groups([Group::ZONE_READ])]
    public ?string $stationName;

    public static function fromEntity(HidingZone $zone): self
    {
        $self = new self();
        $self->roundUuid = $zone->getRound()->getUuid();
        $self->lat = $zone->getStationPoint()->getLatitude();
        $self->lng = $zone->getStationPoint()->getLongitude();
        $self->radiusMeters = $zone->getRadiusMeters();
        $self->stationName = $zone->getStationName();

        return $self;
    }
}
