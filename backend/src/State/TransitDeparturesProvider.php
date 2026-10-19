<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TransitDepartureGroupResource;
use App\ApiResource\TransitDeparturesResource;
use App\Exception\EntityNotFoundException;
use App\Repository\GameGtfsLineRepository;
use App\Repository\GameRepository;
use App\Repository\GameTransitStationRepository;
use App\Service\TransitDeparturesService;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<TransitDeparturesResource>
 */
final readonly class TransitDeparturesProvider implements ProviderInterface
{
    public function __construct(
        private GameRepository $games,
        private GameTransitStationRepository $stations,
        private GameGtfsLineRepository $gtfsLines,
        private TransitDeparturesService $departures,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): TransitDeparturesResource {
        $gameUuid = $uriVariables['uuid'] ?? null;
        $stationId = $uriVariables['stationId'] ?? null;
        if (!is_string($gameUuid)) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }
        if (!is_string($stationId) || $stationId === '') {
            throw new EntityNotFoundException(
                message: 'Transit station not found.',
                errorKey: 'transit_station.not_found',
            );
        }

        $game = $this->games->findOneByUuid($gameUuid);
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }
        $station = $this->stations->findOneByGameAndStationId($game, $stationId);
        if ($station === null) {
            throw new EntityNotFoundException(
                message: 'Transit station not found.',
                errorKey: 'transit_station.not_found',
            );
        }

        $realtimeUrls = [];
        foreach ($this->gtfsLines->findByGame($game) as $line) {
            $source = $line->getGtfsSource();
            $url = $source->getRealtimeUrl();
            if ($url !== null && $url !== '') {
                $realtimeUrls[$source->getUuid()] = $url;
            }
        }

        $fullDay = $this->requestStack->getCurrentRequest()?->query->getBoolean('fullDay') ?? false;
        $data = $this->departures->forStation(
            $game->getUuid(),
            $stationId,
            $realtimeUrls,
            fullDay: $fullDay,
        );

        $resource = new TransitDeparturesResource();
        $resource->stationId = $stationId;
        $resource->uuid = $game->getUuid();
        $resource->stationName = $station->getName() ?? $stationId;
        $resource->timezone = $data['timezone'];
        $resource->serverNow = $data['serverNow'];
        $resource->groups = array_map(
            static fn (array $group): TransitDepartureGroupResource => TransitDepartureGroupResource::fromArray($group),
            $data['groups'],
        );

        return $resource;
    }
}
