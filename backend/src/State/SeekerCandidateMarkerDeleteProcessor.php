<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Repository\SeekerCandidateMarkerRepository;
use App\Service\IdentityResolver;
use App\Service\SeekerCandidateMarkerService;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class SeekerCandidateMarkerDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private SeekerCandidateMarkerRepository $markers,
        private SeekerCandidateMarkerService $markerService,
        private RoundRepository $rounds,
        private RoundMembershipRepository $memberships,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): null {
        $uuid = $uriVariables['uuid'] ?? null;
        $marker = is_string($uuid) ? $this->markers->findOneByUuid($uuid) : null;
        if ($marker === null) {
            throw new EntityNotFoundException(
                message: 'Seeker candidate marker not found.',
                errorKey: 'seeker_candidate.not_found',
            );
        }

        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $player = $this->identity->requirePlayer();
        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($membership === null || $membership->getSide() !== Side::Seeker) {
            throw new FunctionalException(
                message: 'Only a seeker may delete a candidate marker.',
                errorKey: 'seeker_candidate.not_seeker',
            );
        }

        $this->markerService->delete($marker);

        return null;
    }
}
