<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Entity\Round;
use App\Enum\Side;
use App\Exception\FunctionalException;
use App\Repository\RoundMembershipRepository;

/**
 * The read-side gate for hider-private payloads (GET /zone, /street-network). Identity comes from
 * the request's subscriber token (401 identity.* when absent/invalid/left); the side check against
 * the round's membership stays a plain 400.
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
        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);

        if ($membership?->getSide() !== Side::Hider) {
            throw new FunctionalException(
                message: 'Only a hider on this round may read that.',
                errorKey: $errorKey,
            );
        }

        return $player;
    }
}
