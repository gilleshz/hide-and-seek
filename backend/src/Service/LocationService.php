<?php

declare(strict_types=1);

namespace App\Service;

use App\DateFormat;
use App\Dto\LocationPingResult;
use App\Entity\Player;
use App\Entity\PlayerLocation;
use App\Entity\Round;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Exception\FunctionalException;
use App\Repository\PlayerLocationRepository;
use App\Repository\RoundMembershipRepository;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class LocationService
{
    public function __construct(
        private PlayerLocationRepository $locations,
        private RoundMembershipRepository $memberships,
        private MercureJwtService $mercure,
        private HubInterface $hub,
        private EndgameService $endgameService,
        private RoundClock $clock,
        private TimeTrapService $timeTrapService,
    ) {
    }

    public function record(Round $round, Player $player, Point $point, ?float $altitude = null): LocationPingResult
    {
        if ($round->getGame()->getUuid() !== $player->getGame()->getUuid()) {
            throw new FunctionalException(message: 'Player does not belong to this round\'s game.', errorKey: 'location.player_wrong_game');
        }

        if ($round->getStatus() === RoundStatus::Ended) {
            throw new FunctionalException(message: 'Locations cannot be recorded for an ended round.', errorKey: 'location.round_ended');
        }

        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($membership === null) {
            throw new FunctionalException(message: 'Player has not chosen a side for this round.', errorKey: 'location.no_side_chosen');
        }

        $location = new PlayerLocation($round, $player, $point, $altitude);
        $this->locations->save($location);

        $this->publish($round, $membership->getSide(), $location);

        $endgameTriggered = false;
        // A frozen seeker cannot capture: during a move window the stored zone is the one being abandoned.
        if (
            $membership->getSide() === Side::Seeker
            && $round->getEndgameStartedAt() === null
            && !$this->clock->isMoveWindowOpen($round)
            && $this->endgameService->check($round) !== null
        ) {
            $this->endgameService->start($round);
            $endgameTriggered = true;
        }

        if ($membership->getSide() === Side::Seeker) {
            $this->timeTrapService->checkTrip($round, $player, $point);
        }

        return new LocationPingResult($location, $endgameTriggered);
    }

    private function publish(Round $round, Side $side, PlayerLocation $location): void
    {
        $game = $round->getGame();
        $topic = $side === Side::Hider
            ? $this->mercure->hiderLocationsTopic($game, $round)
            : $this->mercure->seekerLocationsTopic($game, $round);

        $payload = json_encode([
            'type' => 'location',
            'playerUuid' => $location->getPlayer()->getUuid(),
            'lat' => $location->getPoint()->getLatitude(),
            'lng' => $location->getPoint()->getLongitude(),
            'recordedAt' => $location->getRecordedAt()->format(DateFormat::ISO8601_UTC),
        ], JSON_THROW_ON_ERROR);

        $this->hub->publish(new Update($topic, $payload, private: true));
    }
}
