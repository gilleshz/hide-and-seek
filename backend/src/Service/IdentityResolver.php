<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\ErrorKey;
use App\Exception\IdentityRequiredException;
use App\Repository\PlayerRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves who is acting on a request from the X-Subscriber-Token header. Identity never comes
 * from the body: player UUIDs are public in the roster, so only a valid, unexpired, non-left
 * player's own token counts.
 */
final readonly class IdentityResolver
{
    public const string HEADER = 'X-Subscriber-Token';

    public function __construct(
        private MercureJwtService $mercure,
        private PlayerRepository $players,
        private RequestStack $requestStack,
    ) {
    }

    public function requirePlayer(): Player
    {
        $token = $this->requestStack->getCurrentRequest()?->headers->get(self::HEADER) ?? '';
        if ($token === '') {
            throw new IdentityRequiredException(
                message: 'A subscriber token is required to act as a player.',
                errorKey: ErrorKey::IDENTITY_TOKEN_MISSING,
            );
        }

        $playerUuid = $this->mercure->subscriberPlayerUuid($token);
        if ($playerUuid === null) {
            throw new IdentityRequiredException(
                message: 'The subscriber token is invalid or expired.',
                errorKey: ErrorKey::IDENTITY_TOKEN_INVALID,
            );
        }

        $player = $this->players->findOneByUuidIncludingLeft($playerUuid);
        if ($player === null) {
            throw new IdentityRequiredException(
                message: 'The subscriber token names an unknown player.',
                errorKey: ErrorKey::IDENTITY_PLAYER_NOT_FOUND,
            );
        }

        // Leave kills the token for every REST action; rejoining (name + join secret) revives it.
        if ($player->hasLeft()) {
            throw new IdentityRequiredException(
                message: 'This player has left the game.',
                errorKey: ErrorKey::IDENTITY_PLAYER_LEFT,
            );
        }

        return $player;
    }
}
