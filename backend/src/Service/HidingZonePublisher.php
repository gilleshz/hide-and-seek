<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\HidingZone;
use App\Entity\Round;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class HidingZonePublisher
{
    public function __construct(
        private MercureJwtService $mercure,
        private HubInterface $hub,
        private StreetNetworkService $streetNetworks,
    ) {
    }

    /**
     * The station point rides the hider-only topic because it is a coordinate a seeker must never learn;
     * the seeker-only topic carries the bare radius so seekers know it changed, not where the zone sits.
     * Hiders read the radius off their own event, so it is not sent twice. Every mutation path funnels
     * through here, which is why the street-network row is enqueued here rather than at each call site.
     */
    public function publishZone(Round $round, HidingZone $zone): void
    {
        $this->hub->publish(new Update(
            $this->mercure->hiderLocationsTopic($round->getGame(), $round),
            json_encode([
                'type' => 'zone',
                'roundUuid' => $round->getUuid(),
                'lat' => $zone->getStationPoint()->getLatitude(),
                'lng' => $zone->getStationPoint()->getLongitude(),
                'radiusMeters' => $zone->getRadiusMeters(),
            ], JSON_THROW_ON_ERROR),
            private: true,
        ));

        $this->hub->publish(new Update(
            $this->mercure->seekerZoneTopic($round->getGame(), $round),
            json_encode(['type' => 'zone-radius', 'radiusMeters' => $zone->getRadiusMeters()], JSON_THROW_ON_ERROR),
            private: true,
        ));

        $this->streetNetworks->enqueueForZone($round, $zone);
    }
}
