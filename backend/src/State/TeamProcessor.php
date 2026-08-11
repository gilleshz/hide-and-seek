<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\TeamResource;
use App\Dto\TeamInput;
use App\Exception\EntityNotFoundException;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Service\TeamService;

/**
 * @implements ProcessorInterface<TeamInput, TeamResource>
 */
final readonly class TeamProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private TeamService $teamService,
        private MercureJwtService $mercure,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): TeamResource {
        $this->validator->validate($data);

        $roundUuid = $uriVariables['roundUuid'] ?? null;
        if (!is_string($roundUuid)) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $player = $this->identity->requirePlayer();
        $membership = $this->teamService->choose($roundUuid, $player->getUuid(), $data->side);
        $game = $membership->getRound()->getGame();
        $round = $membership->getRound();

        $topics = [
            ...$this->mercure->baselineTopics($game, $round, $membership->getSide()),
            ...$this->mercure->locationTopics($game, $round, $membership->getSide()),
            ...$this->mercure->seekerOnlyTopics($game, $round, $membership->getSide()),
            $this->mercure->playerEndgameTopic($membership->getPlayer()->getUuid()),
        ];

        return TeamResource::create(
            $membership,
            $this->mercure->issueSubscriberToken($topics, $membership->getPlayer()->getUuid()),
            $topics,
        );
    }
}
