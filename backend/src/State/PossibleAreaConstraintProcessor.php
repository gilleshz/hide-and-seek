<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\PossibleAreaConstraintResource;
use App\Dto\ManualConstraintDraft;
use App\Dto\PossibleAreaConstraintInput;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\ConstraintMode;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\IdentityResolver;
use App\Service\PossibleAreaService;

/**
 * @implements ProcessorInterface<PossibleAreaConstraintInput, PossibleAreaConstraintResource>
 */
final readonly class PossibleAreaConstraintProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private RoundRepository $rounds,
        private RoundMembershipRepository $memberships,
        private PossibleAreaService $possibleAreaService,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): PossibleAreaConstraintResource {
        $this->validator->validate($data);

        $round = $this->resolveRound($uriVariables);
        $membership = $this->resolveSeekerMembership($round, $this->identity->requirePlayer());

        $mode = ConstraintMode::tryFrom($data->mode);
        if ($mode === null) {
            throw new FunctionalException(
                message: 'Unknown constraint mode.',
                errorKey: 'possible_area_constraint.invalid_mode',
            );
        }

        $draft = new ManualConstraintDraft($data->geoJson, $mode, $this->resolveLabel($data->label, $mode));
        $uuid = $this->possibleAreaService->addManualConstraint($round, $membership, $draft);

        return PossibleAreaConstraintResource::fromDraft($draft, $uuid, $membership->getPlayer()->getDisplayName());
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function resolveRound(array $uriVariables): Round
    {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        return $round;
    }

    private function resolveSeekerMembership(Round $round, Player $player): RoundMembership
    {
        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($membership === null || $membership->getSide() !== Side::Seeker) {
            throw new FunctionalException(
                message: 'Only a seeker may draw a search-area constraint.',
                errorKey: 'possible_area_constraint.not_seeker',
            );
        }

        return $membership;
    }

    private function resolveLabel(?string $label, ConstraintMode $mode): string
    {
        if ($label !== null && trim($label) !== '') {
            return $label;
        }

        return $mode === ConstraintMode::Include ? 'Included area' : 'Excluded area';
    }
}
