<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ProfileResource;
use App\Service\ProfileIdentityResolver;

/**
 * @implements ProviderInterface<ProfileResource>
 */
final readonly class ProfileProvider implements ProviderInterface
{
    public function __construct(
        private ProfileIdentityResolver $resolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProfileResource
    {
        return ProfileResource::fromIdentity($this->resolver->resolve());
    }
}
