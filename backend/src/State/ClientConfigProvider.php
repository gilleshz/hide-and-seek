<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ClientConfigResource;
use App\Service\MapStyleService;

/**
 * @implements ProviderInterface<ClientConfigResource>
 */
final readonly class ClientConfigProvider implements ProviderInterface
{
    public function __construct(
        private MapStyleService $mapStyleService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ClientConfigResource
    {
        $stadiaAvailable = $this->mapStyleService->stadiaAvailable();
        $thunderforestAvailable = $this->mapStyleService->thunderforestAvailable();
        $maptilerAvailable = $this->mapStyleService->maptilerAvailable();
        $anyAvailable = $stadiaAvailable || $thunderforestAvailable || $maptilerAvailable;

        $resource = new ClientConfigResource();
        $resource->stadiaApiKey = $stadiaAvailable ? $this->mapStyleService->stadiaApiKey() : null;
        $resource->thunderforestApiKey = $thunderforestAvailable ? $this->mapStyleService->thunderforestApiKey() : null;
        $resource->maptilerApiKey = $maptilerAvailable ? $this->mapStyleService->maptilerApiKey() : null;
        $resource->mapStyleAvailable = $anyAvailable;
        $resource->availableStyles = $anyAvailable ? $this->mapStyleService->availableStyles() : [];

        return $resource;
    }
}
