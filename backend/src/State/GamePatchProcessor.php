<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\GameResource;
use App\Dto\GameConfigInput;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\RoundRepository;
use App\Service\GameService;
use App\Service\IdentityResolver;

/**
 * @implements ProcessorInterface<mixed, GameResource>
 */
final readonly class GamePatchProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private GameService $gameService,
        private RoundRepository $rounds,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): GameResource {
        if (!$data instanceof GameConfigInput) {
            throw new FunctionalException(
                message: 'The game configuration payload is invalid.',
                errorKey: 'game_patch.invalid_payload',
            );
        }
        $this->validator->validate($data);

        $uuid = $uriVariables['uuid'] ?? null;
        if (!is_string($uuid)) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $player = $this->identity->requirePlayer();
        $game = $this->gameService->updateConfig($uuid, $data, $player->getUuid());
        $round = $this->rounds->findActiveByGame($game) ?? $this->rounds->findLatestByGame($game);
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        return GameResource::fromEntity($game, $round);
    }
}
