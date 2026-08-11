<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\LeaveResource;
use App\Exception\EntityNotFoundException;
use App\Service\IdentityResolver;
use App\Service\LeaveService;

/**
 * @implements ProcessorInterface<mixed, LeaveResource>
 */
final readonly class LeaveProcessor implements ProcessorInterface
{
    public function __construct(
        private LeaveService $leaveService,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): LeaveResource {
        $gameUuid = $uriVariables['gameUuid'] ?? null;
        if (!is_string($gameUuid)) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $player = $this->identity->requirePlayer();

        return LeaveResource::removed($gameUuid, $this->leaveService->leave($gameUuid, $player->getUuid())->getUuid());
    }
}
