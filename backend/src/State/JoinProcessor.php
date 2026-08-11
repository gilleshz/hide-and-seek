<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\JoinResource;
use App\Dto\JoinInput;
use App\Exception\EntityNotFoundException;
use App\Service\JoinService;
use App\Service\MercureJwtService;

/**
 * @implements ProcessorInterface<JoinInput, JoinResource>
 */
final readonly class JoinProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private JoinService $joinService,
        private MercureJwtService $mercure,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): JoinResource {
        $this->validator->validate($data);

        $gameKey = $uriVariables['gameKey'] ?? null;
        if (!is_string($gameKey)) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $result = $this->joinService->join($gameKey, $data->name, $data->password);
        $player = $result->player;
        $game = $player->getGame();
        $round = $this->joinService->roundFor($game);
        $topics = [
            ...$this->mercure->baselineTopics($game, $round),
            $this->mercure->playerEndgameTopic($player->getUuid()),
        ];

        return JoinResource::create(
            $player,
            $game,
            $round,
            $this->mercure->issueSubscriberToken($topics, $player->getUuid()),
            $topics,
        );
    }
}
