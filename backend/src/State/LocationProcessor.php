<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\LocationResource;
use App\Dto\LocationInput;
use App\Exception\EntityNotFoundException;
use App\Repository\RoundRepository;
use App\Service\IdentityResolver;
use App\Service\LocationService;
use App\Service\RateLimits;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;

/**
 * @implements ProcessorInterface<LocationInput, LocationResource>
 */
final readonly class LocationProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private RoundRepository $rounds,
        private LocationService $locationService,
        private IdentityResolver $identity,
        private RateLimits $rateLimits,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): LocationResource {
        $this->validator->validate($data);

        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $player = $this->identity->requirePlayer();
        $this->rateLimits->locationIngest($player->getUuid());

        $result = $this->locationService->record(
            $round,
            $player,
            new Point($data->lng, $data->lat),
            $data->altitude,
        );

        return LocationResource::fromPing($result);
    }
}
