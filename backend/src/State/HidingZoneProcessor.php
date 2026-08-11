<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\HidingZoneResource;
use App\Dto\HidingZoneInput;
use App\Dto\ZonePlacement;
use App\Exception\EntityNotFoundException;
use App\Service\HidingZoneService;
use App\Service\IdentityResolver;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;

/**
 * @implements ProcessorInterface<HidingZoneInput, HidingZoneResource>
 */
final readonly class HidingZoneProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private HidingZoneService $hidingZoneService,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): HidingZoneResource {
        $this->validator->validate($data);

        $roundUuid = $uriVariables['roundUuid'] ?? null;
        if (!is_string($roundUuid)) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $player = $this->identity->requirePlayer();

        $zone = $this->hidingZoneService->setZone(
            $roundUuid,
            $player->getUuid(),
            new ZonePlacement(
                point: new Point($data->lng, $data->lat),
                radiusMeters: $data->radiusMeters,
                stationName: $data->stationName,
            ),
        );

        return HidingZoneResource::fromEntity($zone);
    }
}
