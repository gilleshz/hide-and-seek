<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\ProfileResource;
use App\Dto\ProfileNameInput;
use App\Repository\IdentityRepository;
use App\Service\IdentityRosterPublisher;
use App\Service\ProfileIdentityResolver;

/**
 * @implements ProcessorInterface<ProfileNameInput, ProfileResource>
 */
final readonly class ProfileRenameProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private ProfileIdentityResolver $resolver,
        private IdentityRepository $identities,
        private IdentityRosterPublisher $roster,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): ProfileResource {
        $this->validator->validate($data);

        $identity = $this->resolver->resolve();
        $identity->rename($data->name);
        $this->identities->save($identity);
        $this->roster->publish($identity);

        return ProfileResource::fromIdentity($identity);
    }
}
