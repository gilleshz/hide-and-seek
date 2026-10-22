<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\AvatarService;
use App\Service\ProfileIdentityResolver;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class ProfileAvatarDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private ProfileIdentityResolver $resolver,
        private AvatarService $avatars,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): null {
        $this->avatars->remove($this->resolver->resolve());

        return null;
    }
}
