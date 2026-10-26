<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\AreaFitResource;
use App\Dto\AreaFitInput;
use App\Service\AreaFitService;

/**
 * @implements ProcessorInterface<AreaFitInput, AreaFitResource>
 */
final readonly class AreaFitProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private AreaFitService $areaFitService,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): AreaFitResource {
        $this->validator->validate($data);

        $resource = new AreaFitResource();
        $resource->areas = $this->areaFitService->fit($data);

        return $resource;
    }
}
