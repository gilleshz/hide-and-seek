<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Dto\AccountInput;
use App\Serializer\Group;
use App\State\AccountProcessor;

#[ApiResource(
    shortName: 'Account',
    operations: [
        new Post(
            uriTemplate: '/accounts',
            input: AccountInput::class,
            processor: AccountProcessor::class,
            status: 204,
            output: false,
        ),
    ],
    normalizationContext: ['groups' => [Group::ACCOUNT_READ]],
    denormalizationContext: ['groups' => [Group::ACCOUNT_WRITE]],
)]
final class AccountResource
{
}
