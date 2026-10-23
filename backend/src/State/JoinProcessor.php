<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\JoinResource;
use App\Dto\JoinInput;
use App\Dto\JoinResult;
use App\Exception\EntityNotFoundException;
use App\Service\IdentityService;
use App\Service\JoinService;
use App\Service\MercureJwtService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @implements ProcessorInterface<JoinInput, JoinResource>
 */
final readonly class JoinProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private IdentityService $identityService,
        private JoinService $joinService,
        private MercureJwtService $mercure,
        private EntityManagerInterface $entityManager,
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

        // The lookup lock stays held until the seat insert commits, so concurrent joins cannot race it.
        $result = $this->entityManager->wrapInTransaction(
            function () use ($gameKey, $data): JoinResult {
                $identity = $this->identityService->resolveOrCreate($data->deviceKey, $data->name);

                return $this->joinService->join($gameKey, $identity);
            },
        );
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
            $this->mercure->issueSubscriberToken(
                $topics,
                $player->getUuid(),
                $player->getIdentity()->getTokenEpoch(),
            ),
            $topics,
        );
    }
}
