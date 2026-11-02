<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\TransitLinePreviewResource;
use App\Dto\TransitLinePreviewInput;
use App\Exception\FunctionalException;
use App\Service\NetworkBoundaryService;
use App\Service\TransitService;

/**
 * @implements ProcessorInterface<TransitLinePreviewInput, TransitLinePreviewResource>
 */
final readonly class TransitLinePreviewProcessor implements ProcessorInterface
{
    /** A GTFS feed repeats every trip variant, so a raw shape is far heavier than a setup map needs. */
    private const int MAX_SHAPE_POINTS = 200;

    public function __construct(
        private ValidatorInterface $validator,
        private TransitService $transitService,
        private NetworkBoundaryService $networkBoundary,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): TransitLinePreviewResource {
        $this->validator->validate($data);

        $features = [...$this->osmFeatures($data->osmIds), ...$this->gtfsFeatures($data->gtfsLines)];

        if ($features === []) {
            throw new FunctionalException(
                message: 'The selected lines have no geometry to show.',
                errorKey: 'transit.preview_no_geometry',
            );
        }

        $resource = new TransitLinePreviewResource();
        $resource->geoJson = json_encode(
            ['type' => 'FeatureCollection', 'features' => $features],
            JSON_THROW_ON_ERROR,
        );

        return $resource;
    }

    /**
     * @param list<string> $osmIds
     * @return list<array<string, mixed>>
     */
    private function osmFeatures(array $osmIds): array
    {
        if ($osmIds === []) {
            return [];
        }

        try {
            $geoJson = $this->transitService->previewGeometry($osmIds);
        } catch (\RuntimeException $e) {
            throw new FunctionalException(
                message: 'Could not fetch the geometry of the selected lines.',
                errorKey: 'transit.preview_failed',
                previous: $e,
            );
        }

        $decoded = $geoJson === null ? null : json_decode($geoJson, true, 512, JSON_THROW_ON_ERROR);
        $features = is_array($decoded) ? ($decoded['features'] ?? null) : null;
        if (!is_array($features)) {
            return [];
        }

        $parsed = [];
        foreach ($features as $feature) {
            if (is_array($feature)) {
                /** @var array<string, mixed> $feature */
                $parsed[] = $feature;
            }
        }

        return $parsed;
    }

    /**
     * A shape is painted in its route's colour when the feed declares one; without it the layer's
     * fallback colour applies.
     *
     * @param list<array<string, mixed>> $gtfsLines
     * @return list<array<string, mixed>>
     */
    private function gtfsFeatures(array $gtfsLines): array
    {
        if ($gtfsLines === []) {
            return [];
        }

        $features = [];
        foreach ($this->networkBoundary->gtfsShapes($gtfsLines) as $shape) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'LineString', 'coordinates' => self::simplify($shape['coordinates'])],
                'properties' => $shape['colour'] === '' ? new \stdClass() : ['colour' => $shape['colour']],
            ];
        }

        return $features;
    }

    /**
     * @param list<array{0: float, 1: float}> $line
     * @return list<array{0: float, 1: float}>
     */
    private static function simplify(array $line): array
    {
        $count = count($line);
        if ($count <= self::MAX_SHAPE_POINTS) {
            return $line;
        }

        $step = (int) ceil($count / self::MAX_SHAPE_POINTS);
        $points = [];
        foreach ($line as $index => $point) {
            if ($index % $step === 0) {
                $points[] = $point;
            }
        }
        if (($count - 1) % $step !== 0) {
            $points[] = $line[$count - 1];
        }

        return $points;
    }
}
