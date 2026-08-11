<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Player;
use App\Entity\PossibleAreaConstraint;
use App\Entity\Round;
use App\Enum\ConstraintSource;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\PossibleAreaConstraintRepository;
use App\Repository\RoundMembershipRepository;
use App\Service\IdentityResolver;
use App\Service\PossibleAreaService;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class PossibleAreaConstraintDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private PossibleAreaConstraintRepository $constraints,
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
    ): null {
        $constraint = $this->resolveConstraint($uriVariables);
        if ($constraint->getSource() !== ConstraintSource::Manual) {
            throw new FunctionalException(
                message: 'Only a manual constraint may be deleted.',
                errorKey: 'possible_area_constraint.not_manual',
            );
        }

        $this->assertSeeker($constraint->getRound(), $this->identity->requirePlayer());
        $this->possibleAreaService->deleteManualConstraint($constraint);

        return null;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function resolveConstraint(array $uriVariables): PossibleAreaConstraint
    {
        $uuid = $uriVariables['uuid'] ?? null;
        $constraint = is_string($uuid) ? $this->constraints->findOneByUuid($uuid) : null;
        if ($constraint === null) {
            throw new EntityNotFoundException(
                message: 'Possible-area constraint not found.',
                errorKey: 'possible_area_constraint.not_found',
            );
        }

        return $constraint;
    }

    private function assertSeeker(Round $round, Player $player): void
    {
        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($membership === null || $membership->getSide() !== Side::Seeker) {
            throw new FunctionalException(
                message: 'Only a seeker may delete a search-area constraint.',
                errorKey: 'possible_area_constraint.not_seeker',
            );
        }
    }
}
