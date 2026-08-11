<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\HidingZoneResource;
use App\Enum\ZoneCard;
use App\Exception\FunctionalException;
use App\Service\HidingZoneService;
use App\Service\IdentityResolver;
use App\Service\UploadedImageReader;

/**
 * @implements ProcessorInterface<mixed, HidingZoneResource>
 */
final readonly class ZoneCardProcessor implements ProcessorInterface
{
    public function __construct(
        private HidingZoneService $hidingZoneService,
        private UploadedImageReader $upload,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): HidingZoneResource {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        if (!is_string($roundUuid)) {
            throw new FunctionalException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $card = ZoneCard::tryFrom($this->upload->requiredField('card'));
        if ($card === null) {
            throw new FunctionalException(message: 'Unknown zone card.', errorKey: 'zone_card.unknown');
        }

        $cardPhoto = $this->upload->image();
        $player = $this->identity->requirePlayer();

        return HidingZoneResource::fromEntity(
            $this->hidingZoneService->playCard($roundUuid, $player->getUuid(), $card, $cardPhoto),
        );
    }
}
