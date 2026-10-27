<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\BoundaryPreviewResource;
use App\Dto\BoundaryPreviewInput;
use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Service\BoundaryService;
use App\Service\NetworkBoundaryService;

/**
 * @implements ProcessorInterface<BoundaryPreviewInput, BoundaryPreviewResource>
 */
final readonly class BoundaryPreviewProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private BoundaryService $boundaryService,
        private NetworkBoundaryService $networkBoundary,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): BoundaryPreviewResource {
        $this->validator->validate($data);

        if ($data->areas !== []) {
            $geoJson = $this->boundaryService->previewBoundary($data->areas);
        } elseif ($data->osmLineIds !== [] || $data->gtfsLines !== []) {
            $geoJson = $this->networkBoundary->preview($data->osmLineIds, $data->gtfsLines, $data->size, $data->edition);
        } else {
            throw new FunctionalException(
                message: 'Select at least one transit line or area.',
                errorKey: ErrorKey::AREA_FIT_NO_LINES,
            );
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($geoJson, true, 512, JSON_THROW_ON_ERROR);
        if (!isset($decoded['features']) || !is_array($decoded['features']) || count($decoded['features']) === 0) {
            throw new FunctionalException(message: 'No geometry found for the selected areas or lines.', errorKey: 'boundary_preview.no_geometry');
        }

        $resource = new BoundaryPreviewResource();
        $resource->geoJson = $geoJson;

        return $resource;
    }
}
