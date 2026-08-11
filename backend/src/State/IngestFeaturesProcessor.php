<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\IngestFeaturesResource;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\GameRepository;
use App\Service\HeavyWorkGuard;
use App\Service\IdentityResolver;
use App\Service\OverpassService;

/**
 * @implements ProcessorInterface<mixed, IngestFeaturesResource>
 */
final readonly class IngestFeaturesProcessor implements ProcessorInterface
{
    public function __construct(
        private GameRepository $games,
        private OverpassService $overpassService,
        private HeavyWorkGuard $heavyWork,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): IngestFeaturesResource {
        $gameUuid = $uriVariables['gameUuid'] ?? null;
        if (!is_string($gameUuid)) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $game = $this->games->findOneByUuid($gameUuid);
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $player = $this->identity->requirePlayer();
        if ($game->getUuid() !== $player->getGame()->getUuid()) {
            throw new FunctionalException(
                message: 'Player does not belong to this game.',
                errorKey: 'game.player_wrong_game',
            );
        }

        $count = $this->heavyWork->run(fn () => $this->overpassService->ingestFeatures($game));

        return IngestFeaturesResource::result($count);
    }
}
