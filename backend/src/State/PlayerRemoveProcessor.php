<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PlayerRemoveResource;
use App\Exception\EntityNotFoundException;
use App\Service\GameService;
use App\Service\IdentityResolver;

/**
 * @implements ProcessorInterface<mixed, PlayerRemoveResource>
 */
final readonly class PlayerRemoveProcessor implements ProcessorInterface
{
    public function __construct(
        private GameService $gameService,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): PlayerRemoveResource {
        $gameUuid = $uriVariables['gameUuid'] ?? null;
        if (!is_string($gameUuid)) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }
        $playerUuid = $uriVariables['playerUuid'] ?? null;
        if (!is_string($playerUuid)) {
            throw new EntityNotFoundException(message: 'Player not found in this game.', errorKey: 'player.not_found');
        }

        $acting = $this->identity->requirePlayer();
        $player = $this->gameService->removePlayer($gameUuid, $playerUuid, $acting->getUuid());

        return PlayerRemoveResource::fromPlayer($player);
    }
}
