<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\TransitLineResource;
use App\Dto\TransitLineDiscoveryInput;
use App\Exception\FunctionalException;
use App\Serializer\Group;
use App\Service\TransitService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * @implements ProcessorInterface<TransitLineDiscoveryInput, JsonResponse>
 */
final readonly class TransitLineDiscoveryProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private TransitService $transitService,
        private SerializerInterface $serializer,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): JsonResponse {
        $this->validator->validate($data);

        try {
            $rawLines = $this->transitService->discoverLines($data->areas, $data->routeTypes);
        } catch (\RuntimeException $e) {
            throw new FunctionalException(
                message: 'Transit discovery failed. The query may have timed out, try selecting fewer route types or a smaller area.',
                errorKey: 'transit.discovery_failed',
                previous: $e,
            );
        }

        $resources = array_map(static function (array $raw): TransitLineResource {
            $resource = new TransitLineResource();
            $resource->osmId = $raw['osmId'];
            $resource->osmType = $raw['osmType'];
            $resource->ref = $raw['ref'];
            $resource->name = $raw['name'];
            $resource->nameEn = $raw['nameEn'];
            $resource->colour = $raw['colour'];
            $resource->routeType = $raw['routeType'];
            $resource->network = $raw['network'];
            $resource->operator = $raw['operator'];

            return $resource;
        }, $rawLines);

        $json = $this->serializer->serialize($resources, 'json', [
            'groups' => [Group::TRANSIT_LINE_READ],
        ]);

        return new JsonResponse($json, 201, [], true);
    }
}
