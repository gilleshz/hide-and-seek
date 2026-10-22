<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Entity\Identity;
use App\Serializer\Group;
use App\State\ProfileAvatarDeleteProcessor;
use App\State\ProfileAvatarUploadProcessor;
use App\State\ProfileProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'Profile',
    operations: [
        new Get(
            uriTemplate: '/profile',
            provider: ProfileProvider::class,
        ),
        new Post(
            uriTemplate: '/profile/avatar',
            input: false,
            processor: ProfileAvatarUploadProcessor::class,
        ),
        new Delete(
            uriTemplate: '/profile/avatar',
            input: false,
            output: false,
            processor: ProfileAvatarDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::PROFILE_READ]],
)]
final class ProfileResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::PROFILE_READ])]
    public string $name;

    #[Groups([Group::PROFILE_READ])]
    public ?string $avatarRef = null;

    public static function fromIdentity(Identity $identity): self
    {
        $self = new self();
        $self->name = $identity->getName();
        $self->avatarRef = $identity->getAvatarRef();

        return $self;
    }
}
