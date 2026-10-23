<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Identity;
use App\Repository\PlayerRepository;
use Psr\Log\LoggerInterface;

/**
 * A renamed identity or a new avatar must reach every roster the identity is seated in. The
 * broadcast is best-effort: failing it must not roll back the persisted profile change.
 */
final readonly class IdentityRosterPublisher
{
    public function __construct(
        private PlayerRepository $players,
        private RosterNotifier $roster,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(Identity $identity): void
    {
        try {
            foreach ($this->players->findActiveGamesByIdentity($identity) as $game) {
                $this->roster->publishChanged($game);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Identity roster publish failed.', [
                'identity' => $identity->getUuid(),
                'exception' => $exception,
            ]);
        }
    }
}
