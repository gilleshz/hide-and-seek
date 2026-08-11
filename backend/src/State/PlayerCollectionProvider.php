<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerResource;
use App\Exception\EntityNotFoundException;
use App\Repository\GameRepository;
use App\Service\RosterService;

/**
 * @implements ProviderInterface<PlayerResource>
 */
final readonly class PlayerCollectionProvider implements ProviderInterface
{
    public function __construct(
        private GameRepository $games,
        private RosterService $roster,
    ) {
    }

    /**
     * @return list<PlayerResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $gameKey = $uriVariables['gameKey'] ?? null;
        $game = is_string($gameKey) ? $this->games->findOneByUuid($gameKey) : null;
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        return $this->roster->roster($game);
    }
}
