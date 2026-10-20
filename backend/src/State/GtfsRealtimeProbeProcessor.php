<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\GtfsRealtimeProbeResource;
use App\Dto\GtfsRealtimeProbeInput;
use App\Service\TransitRealtimeService;

/**
 * @implements ProcessorInterface<GtfsRealtimeProbeInput, GtfsRealtimeProbeResource>
 */
final readonly class GtfsRealtimeProbeProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private TransitRealtimeService $realtime,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): GtfsRealtimeProbeResource {
        $this->validator->validate($data);

        $probe = $this->realtime->probe($data->url);

        $resource = new GtfsRealtimeProbeResource();
        $resource->status = $probe['status'];
        $resource->feedType = $probe['feedType'];
        $resource->headerAgeSeconds = $probe['headerAgeSeconds'];
        $resource->tripCount = $probe['tripCount'];

        return $resource;
    }
}
