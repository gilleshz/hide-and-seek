<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\StreetNetworkResource;
use App\Exception\FunctionalException;
use App\Service\StreetNetworkService;

/**
 * @implements ProviderInterface<StreetNetworkResource>
 */
final readonly class StreetNetworkProvider implements ProviderInterface
{
    public function __construct(
        private StreetNetworkService $streetNetworkService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StreetNetworkResource
    {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        if (!is_string($roundUuid)) {
            throw new FunctionalException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $network = $this->streetNetworkService->forSubscriber($roundUuid);

        return $network === null
            ? StreetNetworkResource::pending($roundUuid)
            : StreetNetworkResource::fromEntity($network);
    }
}
