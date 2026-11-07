<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\Dto\TrafficAlert;
use App\Entity\Game;
use App\Serializer\Group;
use App\State\TrafficAlertsProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'TrafficAlerts',
    operations: [
        new Get(
            uriTemplate: '/games/{uuid}/traffic-alerts',
            uriVariables: [
                'uuid' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            provider: TrafficAlertsProvider::class,
            cacheHeaders: ['cache_control' => ['max_age' => 0, 'must_revalidate' => true]],
        ),
    ],
    normalizationContext: ['groups' => [Group::TRAFFIC_ALERTS_READ]],
)]
final class TrafficAlertsResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public string $uuid;

    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public int $serverNow;

    /** @var list<TrafficAlert> */
    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public array $alerts = [];
}
