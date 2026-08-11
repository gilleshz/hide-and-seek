<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PossibleAreaConstraintResource;
use App\Enum\Side;
use App\ErrorKey;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\PossibleAreaConstraintRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\IdentityResolver;

/**
 * @implements ProviderInterface<PossibleAreaConstraintResource>
 */
final readonly class PossibleAreaConstraintProvider implements ProviderInterface
{
    public function __construct(
        private RoundRepository $rounds,
        private PossibleAreaConstraintRepository $constraints,
        private RoundMembershipRepository $memberships,
        private IdentityResolver $identity,
    ) {
    }

    /**
     * @return list<PossibleAreaConstraintResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
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

        return array_map(
            static fn (array $row): PossibleAreaConstraintResource => PossibleAreaConstraintResource::fromRow($row),
            $this->constraints->findManualWithDetailsByRound($round),
        );
    }
}
