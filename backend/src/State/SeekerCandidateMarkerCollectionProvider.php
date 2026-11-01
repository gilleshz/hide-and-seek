<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SeekerCandidateMarkerResource;
use App\Entity\SeekerCandidateMarker;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Repository\SeekerCandidateMarkerRepository;
use App\Service\IdentityResolver;

/**
 * @implements ProviderInterface<SeekerCandidateMarkerResource>
 */
final readonly class SeekerCandidateMarkerCollectionProvider implements ProviderInterface
{
    public function __construct(
        private RoundRepository $rounds,
        private SeekerCandidateMarkerRepository $markers,
        private RoundMembershipRepository $memberships,
        private IdentityResolver $identity,
    ) {
    }

    /**
     * @return list<SeekerCandidateMarkerResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $player = $this->identity->requirePlayer();
        $side = $this->memberships->findOneByRoundAndPlayer($round, $player)?->getSide();
        if ($side !== Side::Seeker && $side !== Side::Spectator) {
            throw new FunctionalException(
                message: 'Only a seeker or spectator may read the candidate markers.',
                errorKey: 'seeker_candidate.not_seeker',
            );
        }

        return array_map(
            static fn (SeekerCandidateMarker $marker): SeekerCandidateMarkerResource
                => SeekerCandidateMarkerResource::fromEntity($marker),
            $this->markers->findByRound($round),
        );
    }
}
