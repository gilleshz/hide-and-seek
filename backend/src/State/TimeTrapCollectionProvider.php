<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TimeTrapResource;
use App\Entity\TimeTrap;
use App\Exception\EntityNotFoundException;
use App\Repository\RoundRepository;
use App\Repository\TimeTrapRepository;

/**
 * @implements ProviderInterface<TimeTrapResource>
 */
final readonly class TimeTrapCollectionProvider implements ProviderInterface
{
    public function __construct(
        private RoundRepository $rounds,
        private TimeTrapRepository $traps,
    ) {
    }

    /**
     * @return list<TimeTrapResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        return array_map(
            static fn (TimeTrap $trap): TimeTrapResource => TimeTrapResource::fromEntity($trap),
            $this->traps->findByRound($round),
        );
    }
}
