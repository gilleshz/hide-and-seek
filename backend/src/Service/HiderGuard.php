<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Entity\Round;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Repository\RoundMembershipRepository;

/**
 * The read-side gate for hider-private payloads (GET /zone, /street-network). Identity comes from
 * the request's subscriber token (401 identity.* when absent/invalid/left); the side check against
 * the round's membership stays a plain 400. Only the zone read admits a spectator.
 */
final readonly class HiderGuard
{
    public function __construct(
        private IdentityResolver $identity,
        private RoundMembershipRepository $memberships,
    ) {
    }

    public function assertHider(Round $round, string $errorKey): Player
    {
        $player = $this->identity->requirePlayer();
        $this->assertRoundStarted($round);
        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);

        if ($membership?->getSide() !== Side::Hider) {
            throw new FunctionalException(
                message: 'Only a hider on this round may read that.',
                errorKey: $errorKey,
            );
        }

        return $player;
    }

    public function assertHiderOrSpectator(Round $round, string $errorKey): Player
    {
        $player = $this->identity->requirePlayer();
        $this->assertRoundStarted($round);
        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        $side = $membership?->getSide();

        if ($side === Side::Hider || $side === Side::Spectator) {
            return $player;
        }

        throw new FunctionalException(
            message: 'Only a hider or spectator on this round may read that.',
            errorKey: $errorKey,
        );
    }

    private function assertRoundStarted(Round $round): void
    {
        if ($round->getStatus() === RoundStatus::Lobby) {
            throw new FunctionalException(
                message: 'This is available once the round starts.',
                errorKey: ErrorKey::ROUND_NOT_STARTED,
            );
        }
    }
}
