<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\GameResource;
use App\Dto\GameInput;
use App\Repository\RoundRepository;
use App\Service\GameService;

/**
 * @implements ProcessorInterface<GameInput, GameResource>
 */
final readonly class GamePostProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private GameService $gameService,
        private RoundRepository $rounds,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): GameResource {
        $this->validator->validate($data);

        $game = $this->gameService->create($data);
        $round = $this->rounds->findActiveByGame($game) ?? $this->rounds->findLatestByGame($game);
        if ($round === null) {
            throw new \LogicException('GameService must create a round alongside every game.');
        }

        return GameResource::fromEntity($game, $round);
    }
}
