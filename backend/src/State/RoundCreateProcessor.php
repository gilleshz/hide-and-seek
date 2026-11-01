<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\RoundResource;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\GameRepository;
use App\Repository\RoundRepository;
use App\Service\IdentityResolver;
use App\Service\RoundService;
use App\Service\SpectatorGuard;

/**
 * @implements ProcessorInterface<mixed, RoundResource>
 */
final readonly class RoundCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private RoundService $roundService,
        private GameRepository $games,
        private RoundRepository $rounds,
        private IdentityResolver $identity,
        private SpectatorGuard $spectatorGuard,
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

        $current = $this->rounds->findActiveByGame($game) ?? $this->rounds->findLatestByGame($game);
        if ($current !== null) {
            $this->spectatorGuard->assertMayManageRounds($game, $current, $player);
        }

        return RoundResource::fromEntity($this->roundService->createNextRound($gameUuid));
    }
}
