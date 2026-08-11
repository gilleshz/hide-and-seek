<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GameResource;
use App\Repository\GameRepository;
use App\Repository\GameTransitLineRepository;
use App\Repository\RoundRepository;

/**
 * @implements ProviderInterface<GameResource>
 */
final readonly class GameProvider implements ProviderInterface
{
    public function __construct(
        private GameRepository $games,
        private RoundRepository $rounds,
        private GameTransitLineRepository $transitLines,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?GameResource
    {
        $uuid = $uriVariables['uuid'] ?? null;
        if (!is_string($uuid)) {
            return null;
        }

        $game = $this->games->findOneByUuid($uuid);
        if ($game === null) {
            return null;
        }

        $round = $this->rounds->findActiveByGame($game) ?? $this->rounds->findLatestByGame($game);
        if ($round === null) {
            return null;
        }

        return GameResource::fromEntity($game, $round, $this->transitLines->findByGame($game));
    }
}
