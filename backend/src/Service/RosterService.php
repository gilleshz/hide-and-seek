<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\PlayerResource;
use App\Entity\Game;
use App\Entity\Player;
use App\Enum\Side;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;

final readonly class RosterService
{
    public function __construct(
        private PlayerRepository $players,
        private RoundRepository $rounds,
        private RoundMembershipRepository $memberships,
    ) {
    }

    /**
     * @return list<PlayerResource>
     */
    public function roster(Game $game): array
    {
        $sideByPlayerId = $this->sideByPlayerId($game);

        return array_map(
            static fn (Player $player): PlayerResource => PlayerResource::fromEntity(
                $player,
                $sideByPlayerId[$player->getId()] ?? null,
            ),
            $this->players->findByGameOrdered($game),
        );
    }

    /**
     * @return array<int, Side>
     */
    private function sideByPlayerId(Game $game): array
    {
        // Sides come from the current round; older rounds' memberships are stale on round 2+.
        $round = $this->rounds->findActiveByGame($game) ?? $this->rounds->findLatestByGame($game);
        if ($round === null) {
            return [];
        }

        $sides = [];
        foreach ($this->memberships->findByRound($round) as $membership) {
            $playerId = $membership->getPlayer()->getId();
            if ($playerId !== null) {
                $sides[$playerId] = $membership->getSide();
            }
        }

        return $sides;
    }
}
