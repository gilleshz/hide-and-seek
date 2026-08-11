<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\RoundResource;
use App\Dto\RoundStartInput;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\RoundRepository;
use App\Service\IdentityResolver;
use App\Service\RoundService;

/**
 * @implements ProcessorInterface<RoundStartInput, RoundResource>
 */
final readonly class RoundStartProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private RoundService $roundService,
        private RoundRepository $rounds,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): RoundResource {
        $this->validator->validate($data);

        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $player = $this->identity->requirePlayer();
        if ($round->getGame()->getUuid() !== $player->getGame()->getUuid()) {
            throw new FunctionalException(
                message: 'Player does not belong to this round\'s game.',
                errorKey: 'round.player_wrong_game',
            );
        }

        return RoundResource::fromEntity($this->roundService->start($roundUuid, $data->hidingPeriodMinutes));
    }
}
