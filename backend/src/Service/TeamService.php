<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RoundMembership;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;

final readonly class TeamService
{
    public function __construct(
        private RoundRepository $rounds,
        private PlayerRepository $players,
        private RoundMembershipRepository $memberships,
        private RosterNotifier $roster,
    ) {
    }

    public function choose(string $roundUuid, string $playerUuid, Side $side): RoundMembership
    {
        $round = $this->rounds->findOneByUuid($roundUuid);
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        // Including left players: a departed player must be told "left" (400), not "unknown" (404).
        $player = $this->players->findOneByUuidIncludingLeft($playerUuid);
        if ($player === null) {
            throw new EntityNotFoundException(message: 'Player not found.', errorKey: 'player.not_found');
        }

        if ($player->hasLeft()) {
            throw new FunctionalException(message: 'A player who left cannot choose a side.', errorKey: 'team.player_left');
        }

        if ($round->getGame()->getUuid() !== $player->getGame()->getUuid()) {
            throw new FunctionalException(message: 'Player does not belong to this round\'s game.', errorKey: 'team.player_wrong_game');
        }

        $existing = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($existing !== null) {
            // Same-side re-confirm stays open mid-round: clients re-mint side-scoped tokens after a round rekey.
            if ($existing->getSide() === $side) {
                return $existing;
            }

            if ($round->getStatus() !== RoundStatus::Lobby) {
                throw new FunctionalException(message: 'Teams can only be changed while the round is in the lobby.', errorKey: 'team.change_only_in_lobby');
            }

            $existing->setSide($side);
            $this->memberships->save($existing);
            $this->roster->publishChanged($round->getGame());

            return $existing;
        }

        if ($round->getStatus() === RoundStatus::Ended) {
            throw new FunctionalException(message: 'Cannot choose a side for an ended round.', errorKey: 'team.round_ended');
        }

        // First choice stays possible mid-round, but seeker-only: a mid-round hider leaks pings.
        if ($round->getStatus() !== RoundStatus::Lobby && $side === Side::Hider) {
            throw new FunctionalException(message: 'Only the seeker side can be joined once the round has left the lobby.', errorKey: 'team.only_seeker_mid_round');
        }

        $membership = new RoundMembership($round, $player, $side);
        $this->memberships->save($membership);
        $this->roster->publishChanged($round->getGame());

        return $membership;
    }
}
