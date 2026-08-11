<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\TimeTrapResource;
use App\Dto\TimeTrapResolutionInput;
use App\Exception\EntityNotFoundException;
use App\Service\IdentityResolver;
use App\Service\TimeTrapService;

/**
 * @implements ProcessorInterface<TimeTrapResolutionInput, TimeTrapResource>
 */
final readonly class TimeTrapResolutionProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private TimeTrapService $timeTrapService,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): TimeTrapResource {
        $this->validator->validate($data);

        $trapUuid = $uriVariables['trapUuid'] ?? null;
        if (!is_string($trapUuid)) {
            throw new EntityNotFoundException(message: 'Time trap not found.', errorKey: 'time_trap.not_found');
        }

        $player = $this->identity->requirePlayer();

        return TimeTrapResource::fromEntity(
            $this->timeTrapService->resolve($trapUuid, $player->getUuid(), $data->confirmed),
        );
    }
}
