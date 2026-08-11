<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Entity\Round;
use App\Enum\Side;
use App\GeoDistance;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerLocationRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

readonly class EndgameService
{
    public function __construct(
        private RoundMembershipRepository $memberships,
        private PlayerLocationRepository $playerLocations,
        private HidingZoneRepository $zones,
        private RoundRepository $rounds,
        private MercureJwtService $mercure,
        private HubInterface $hub,
    ) {
    }

    /**
     * Idempotent: the round flag keeps a repeated poll from re-notifying, and every hider is told a
     * seeker walked in, not just whichever membership a query happened to return first.
     */
    public function start(Round $round): void
    {
        if ($round->getEndgameStartedAt() !== null) {
            return;
        }

        $round->setEndgameStartedAt(new \DateTimeImmutable());
        $this->rounds->save($round);

        $payload = json_encode(['type' => 'endgame', 'endgame' => true], JSON_THROW_ON_ERROR);
        foreach ($this->memberships->findHidersByRound($round) as $membership) {
            $this->hub->publish(new Update(
                $this->mercure->playerEndgameTopic($membership->getPlayer()->getUuid()),
                $payload,
                private: true,
            ));
        }
    }

    public function check(Round $round): ?Player
    {
        $zone = $this->zones->findOneByRound($round);
        if ($zone === null) {
            return null;
        }

        $seekerMemberships = $this->memberships->findByRound($round);

        foreach ($seekerMemberships as $membership) {
            // A seeker who left keeps their membership and their last ping: neither may trigger an endgame.
            if ($membership->getSide() !== Side::Seeker || $membership->getPlayer()->hasLeft()) {
                continue;
            }

            $seekerLocation = $this->playerLocations
                ->findLatestByRoundAndPlayer($round, $membership->getPlayer());

            if ($seekerLocation === null) {
                continue;
            }

            $distance = GeoDistance::metersBetween(
                $seekerLocation->getPoint(),
                $zone->getStationPoint(),
            );

            if ($distance <= $zone->getRadiusMeters()) {
                return $membership->getPlayer();
            }
        }

        return null;
    }
}
