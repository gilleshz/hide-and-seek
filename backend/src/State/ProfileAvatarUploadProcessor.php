<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ProfileResource;
use App\Service\AvatarService;
use App\Service\ProfileIdentityResolver;
use App\Service\UploadedImageReader;

/**
 * @implements ProcessorInterface<mixed, ProfileResource>
 */
final readonly class ProfileAvatarUploadProcessor implements ProcessorInterface
{
    public function __construct(
        private ProfileIdentityResolver $resolver,
        private UploadedImageReader $upload,
        private AvatarService $avatars,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): ProfileResource {
        $identity = $this->resolver->resolve();
        $this->avatars->replace($identity, $this->upload->image());

        return ProfileResource::fromIdentity($identity);
    }
}
