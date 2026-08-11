<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\SeekerCandidateMarkerResource;
use App\Dto\SeekerCandidateMarkerInput;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\IdentityResolver;
use App\Service\SeekerCandidateMarkerService;

/**
 * @implements ProcessorInterface<SeekerCandidateMarkerInput, SeekerCandidateMarkerResource>
 */
final readonly class SeekerCandidateMarkerProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private RoundRepository $rounds,
        private RoundMembershipRepository $memberships,
        private SeekerCandidateMarkerService $markerService,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): SeekerCandidateMarkerResource {
        $this->validator->validate($data);

        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $player = $this->identity->requirePlayer();

        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($membership === null || $membership->getSide() !== Side::Seeker) {
            throw new FunctionalException(
                message: 'Only a seeker may place a candidate marker.',
                errorKey: 'seeker_candidate.not_seeker',
            );
        }

        $marker = $this->markerService->create($round, $player, $data->lat, $data->lng);

        return SeekerCandidateMarkerResource::fromEntity($marker);
    }
}
