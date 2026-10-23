<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\ProfileResource;
use App\Dto\ProfileNameInput;
use App\Service\IdentityService;
use App\Service\ProfileIdentityResolver;

/**
 * @implements ProcessorInterface<ProfileNameInput, ProfileResource>
 */
final readonly class ProfileBootstrapProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private ProfileIdentityResolver $resolver,
        private IdentityService $identityService,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): ProfileResource {
        $this->validator->validate($data);

        $identity = $this->identityService->resolveOrCreate($this->resolver->deviceKey(), $data->name);

        return ProfileResource::fromIdentity($identity);
    }
}
