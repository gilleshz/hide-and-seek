<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TimeTrapResource;
use App\Exception\FunctionalException;
use App\Service\IdentityResolver;
use App\Service\TimeTrapService;
use App\Service\UploadedImageReader;

/**
 * @implements ProcessorInterface<mixed, TimeTrapResource>
 */
final readonly class TimeTrapPlacementProcessor implements ProcessorInterface
{
    public function __construct(
        private TimeTrapService $timeTrapService,
        private UploadedImageReader $upload,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): TimeTrapResource {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        if (!is_string($roundUuid)) {
            throw new FunctionalException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $lat = (float) $this->upload->requiredField('lat');
        $lng = (float) $this->upload->requiredField('lng');
        $cardPhoto = $this->upload->image();
        $player = $this->identity->requirePlayer();

        return TimeTrapResource::fromEntity(
            $this->timeTrapService->place($roundUuid, $player->getUuid(), $lat, $lng, $cardPhoto),
        );
    }
}
