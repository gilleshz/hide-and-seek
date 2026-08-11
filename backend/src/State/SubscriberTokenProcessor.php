<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\SubscriberTokenResource;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;

/**
 * @implements ProcessorInterface<mixed, SubscriberTokenResource>
 */
final readonly class SubscriberTokenProcessor implements ProcessorInterface
{
    public function __construct(
        private RoundRepository $rounds,
        private RoundMembershipRepository $memberships,
        private MercureJwtService $mercure,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): SubscriberTokenResource {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $player = $this->identity->requirePlayer();
        $game = $round->getGame();
        if ($game->getUuid() !== $player->getGame()->getUuid()) {
            throw new FunctionalException(
                message: 'Player does not belong to this round\'s game.',
                errorKey: 'round.player_wrong_game',
            );
        }

        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);

        if ($membership === null) {
            $topics = [
                ...$this->mercure->baselineTopics($game, $round),
                $this->mercure->playerEndgameTopic($player->getUuid()),
            ];
        } else {
            $topics = [
                ...$this->mercure->baselineTopics($game, $round, $membership->getSide()),
                ...$this->mercure->locationTopics($game, $round, $membership->getSide()),
                ...$this->mercure->seekerOnlyTopics($game, $round, $membership->getSide()),
                $this->mercure->playerEndgameTopic($player->getUuid()),
            ];
        }

        return SubscriberTokenResource::create(
            $player->getUuid(),
            $round->getUuid(),
            $this->mercure->issueSubscriberToken($topics, $player->getUuid()),
            $topics,
        );
    }
}
