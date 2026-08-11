<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\State\AreaSearchProvider;

#[ApiResource(
    shortName: 'Area',
    operations: [
        new GetCollection(
            uriTemplate: '/areas',
            provider: AreaSearchProvider::class,
        ),
    ],
)]
final class AreaSearchResource
{
    #[ApiProperty(identifier: true)]
    public string $osmId;

    public string $osmType;
    public string $displayName;
    public ?int $adminLevel = null;
}
