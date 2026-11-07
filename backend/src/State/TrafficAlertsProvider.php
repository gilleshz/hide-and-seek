<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TrafficAlertsResource;
use App\Exception\EntityNotFoundException;
use App\Service\TrafficAlertsService;

/**
 * @implements ProviderInterface<TrafficAlertsResource>
 */
final readonly class TrafficAlertsProvider implements ProviderInterface
{
    public function __construct(
        private TrafficAlertsService $alerts,
    ) {
    }

    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): TrafficAlertsResource {
        $gameUuid = $uriVariables['uuid'] ?? null;
        if (!is_string($gameUuid) || $gameUuid === '') {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $data = $this->alerts->forGame($gameUuid);

        $resource = new TrafficAlertsResource();
        $resource->uuid = $gameUuid;
        $resource->serverNow = $data['serverNow'];
        $resource->alerts = $data['alerts'];

        return $resource;
    }
}
