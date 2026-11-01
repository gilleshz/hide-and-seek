<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\Side;
use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;

final readonly class SpectatorGuard
{
    public function __construct(
        private RoundMembershipRepository $memberships,
        private PlayerRepository $players,
    ) {
    }

    public function assertMayManageRounds(Game $game, Round $round, Player $player): void
    {
        if ($this->memberships->findOneByRoundAndPlayer($round, $player)?->getSide() !== Side::Spectator) {
            return;
        }

        $host = $this->players->findByGameOrdered($game)[0] ?? null;
        if ($host?->getUuid() !== $player->getUuid()) {
            throw new FunctionalException(
                message: 'Only a host spectator may start or create rounds.',
                errorKey: ErrorKey::ROUND_SPECTATOR_READ_ONLY,
            );
        }
    }
}
