<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\Entity\Game;
use App\Serializer\Group;
use App\State\TransitDeparturesProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'TransitDepartures',
    operations: [
        new Get(
            uriTemplate: '/games/{uuid}/transit-stations/{stationId}/departures',
            uriVariables: [
                'uuid' => new Link(fromClass: Game::class, identifiers: ['uuid']),
                'stationId' => new Link(identifiers: ['stationId']),
            ],
            provider: TransitDeparturesProvider::class,
            cacheHeaders: ['cache_control' => ['max_age' => 0, 'must_revalidate' => true]],
        ),
    ],
    normalizationContext: ['groups' => [Group::TRANSIT_DEPARTURES_READ]],
)]
final class TransitDeparturesResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public string $stationId;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public string $uuid;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public string $stationName;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public string $timezone;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public int $serverNow;

    /** @var list<TransitDepartureGroupResource> */
    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public array $groups = [];
}
