<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\RoundResource;
use App\Entity\Round;
use App\Repository\HidingZoneRepository;
use App\Repository\RoundRepository;
use App\RoundTiming;
use App\Service\RoundService;

/**
 * @implements ProviderInterface<RoundResource>
 */
final readonly class RoundProvider implements ProviderInterface
{
    public function __construct(
        private RoundRepository $rounds,
        private RoundService $roundService,
        private HidingZoneRepository $hidingZones,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?RoundResource
    {
        $uuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($uuid) ? $this->rounds->findOneByUuid($uuid) : null;
        if ($round === null) {
            return null;
        }

        $this->roundService->currentStatus($round);
        $zone = $this->hidingZones->findOneByRound($round);

        return RoundResource::fromEntity(
            $round,
            $zone?->getRadiusMeters() ?? $this->defaultRadiusMeters($round),
            $zone !== null,
        );
    }

    private function defaultRadiusMeters(Round $round): float
    {
        $game = $round->getGame();

        return RoundTiming::defaultRadiusMeters($game->getSize(), $game->getEdition());
    }
}
