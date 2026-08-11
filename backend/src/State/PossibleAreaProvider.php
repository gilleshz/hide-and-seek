<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PossibleAreaResource;
use App\Enum\Side;
use App\ErrorKey;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\IdentityResolver;
use App\Service\PossibleAreaService;

/**
 * @implements ProviderInterface<PossibleAreaResource>
 */
final readonly class PossibleAreaProvider implements ProviderInterface
{
    public function __construct(
        private RoundRepository $rounds,
        private PossibleAreaService $possibleAreaService,
        private RoundMembershipRepository $memberships,
        private IdentityResolver $identity,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PossibleAreaResource
    {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        if (!is_string($roundUuid)) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $round = $this->rounds->findOneByUuid($roundUuid);
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $player = $this->identity->requirePlayer();
        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        $sharedWithHiders = $membership?->getSide() === Side::Hider
            && $round->getGame()->isPossibleAreaSharedWithHiders();
        if ($membership?->getSide() !== Side::Seeker && !$sharedWithHiders) {
            throw new FunctionalException(
                message: 'Only the seeker side may read the possible area in this game.',
                errorKey: ErrorKey::POSSIBLE_AREA_SEEKERS_ONLY,
            );
        }

        $geoJson = $this->possibleAreaService->computeCurrent($round);
        $exclusionGeoJson = $this->possibleAreaService->computeExclusion($round);

        return PossibleAreaResource::fromRound($roundUuid, $geoJson, $exclusionGeoJson);
    }
}
