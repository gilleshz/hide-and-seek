<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\PlayerResource;
use App\Entity\Game;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class RosterNotifier
{
    public function __construct(
        private HubInterface $hub,
        private MercureJwtService $mercure,
        private RosterService $roster,
    ) {
    }

    /**
     * Sends the roster in the payload so devices apply it without a GET /games/{uuid}/players round-trip.
     * Exposes nothing new: the same list, sides included, is already readable on this topic with the game-wide API key.
     */
    public function publishChanged(Game $game): void
    {
        $payload = json_encode([
            'type' => 'roster',
            'players' => array_map(
                static fn (PlayerResource $player): array => [
                    'uuid' => $player->uuid,
                    'displayName' => $player->displayName,
                    'side' => $player->side?->value,
                ],
                $this->roster->roster($game),
            ),
        ], JSON_THROW_ON_ERROR);

        $this->hub->publish(new Update($this->mercure->rosterTopic($game), $payload));
    }
}
