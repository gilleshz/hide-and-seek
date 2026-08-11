<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\TransitLinePreviewResource;
use App\Dto\TransitLinePreviewInput;
use App\Exception\FunctionalException;
use App\Service\TransitService;

/**
 * @implements ProcessorInterface<TransitLinePreviewInput, TransitLinePreviewResource>
 */
final readonly class TransitLinePreviewProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private TransitService $transitService,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): TransitLinePreviewResource {
        $this->validator->validate($data);

        try {
            $geoJson = $this->transitService->previewGeometry($data->osmIds);
        } catch (\RuntimeException $e) {
            throw new FunctionalException(
                message: 'Could not fetch the geometry of the selected lines.',
                errorKey: 'transit.preview_failed',
                previous: $e,
            );
        }

        if ($geoJson === null) {
            throw new FunctionalException(
                message: 'The selected lines have no geometry to show.',
                errorKey: 'transit.preview_no_geometry',
            );
        }

        $resource = new TransitLinePreviewResource();
        $resource->geoJson = $geoJson;

        return $resource;
    }
}
