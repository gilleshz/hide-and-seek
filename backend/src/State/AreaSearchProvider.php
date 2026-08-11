<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AreaSearchResource;
use App\Service\NominatimService;

/**
 * @implements ProviderInterface<AreaSearchResource>
 */
final readonly class AreaSearchProvider implements ProviderInterface
{
    public function __construct(
        private NominatimService $nominatimService,
    ) {
    }

    /** @return list<AreaSearchResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $filters = $context['filters'] ?? [];
        $q = is_array($filters) ? ($filters['q'] ?? '') : '';
        if (!is_string($q) || $q === '') {
            return [];
        }

        $areas = $this->nominatimService->searchAreas($q);

        $result = [];
        foreach ($areas as $area) {
            $resource = new AreaSearchResource();
            $resource->osmId = (string) $area->osmId;
            $resource->osmType = $area->osmType;
            $resource->displayName = $area->displayName;
            $resource->adminLevel = $area->adminLevel;
            $result[] = $resource;
        }

        return $result;
    }
}
