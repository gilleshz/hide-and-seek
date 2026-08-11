<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Exception\EntityNotFoundException;
use App\Service\GameService;
use App\Service\IdentityResolver;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class GameDeleteProcessor implements ProcessorInterface
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
    ): null {
        $gameUuid = $uriVariables['uuid'] ?? null;
        if (!is_string($gameUuid)) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $player = $this->identity->requirePlayer();
        $this->gameService->delete($gameUuid, $player->getUuid());

        return null;
    }
}
