<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\RoundResource;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\GameRepository;
use App\Service\IdentityResolver;
use App\Service\RoundService;

/**
 * @implements ProcessorInterface<mixed, RoundResource>
 */
final readonly class RoundCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private RoundService $roundService,
        private GameRepository $games,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): RoundResource {
        $gameUuid = $uriVariables['gameUuid'] ?? null;
        $game = is_string($gameUuid) ? $this->games->findOneByUuid($gameUuid) : null;
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

        return RoundResource::fromEntity($this->roundService->createNextRound($gameUuid));
    }
}
