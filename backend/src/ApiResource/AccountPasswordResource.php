<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Dto\AccountPasswordInput;
use App\Serializer\Group;
use App\State\AccountPasswordProcessor;

#[ApiResource(
    shortName: 'AccountPassword',
    operations: [
        new Post(
            uriTemplate: '/account/password',
            input: AccountPasswordInput::class,
            processor: AccountPasswordProcessor::class,
            status: 204,
            output: false,
        ),
    ],
    denormalizationContext: ['groups' => [Group::ACCOUNT_PASSWORD_WRITE]],
)]
final class AccountPasswordResource
{
}
