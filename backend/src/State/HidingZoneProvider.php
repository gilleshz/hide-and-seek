<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\HidingZoneResource;
use App\Exception\FunctionalException;
use App\Service\HidingZoneService;

/**
 * @implements ProviderInterface<HidingZoneResource>
 */
final readonly class HidingZoneProvider implements ProviderInterface
{
    public function __construct(
        private HidingZoneService $hidingZoneService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?HidingZoneResource
    {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        if (!is_string($roundUuid)) {
            throw new FunctionalException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $zone = $this->hidingZoneService->currentZoneForSubscriber($roundUuid);

        return $zone === null ? null : HidingZoneResource::fromEntity($zone);
    }
}
