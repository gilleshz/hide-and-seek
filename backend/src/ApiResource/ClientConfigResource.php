<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Serializer\Group;
use App\State\ClientConfigProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'ClientConfig',
    operations: [
        new Get(
            uriTemplate: '/client-config',
            provider: ClientConfigProvider::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::CLIENT_CONFIG_READ]],
)]
final class ClientConfigResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::CLIENT_CONFIG_READ])]
    public string $id = 'config';

    #[Groups([Group::CLIENT_CONFIG_READ])]
    public ?string $stadiaApiKey = null;

    #[Groups([Group::CLIENT_CONFIG_READ])]
    public ?string $thunderforestApiKey = null;

    #[Groups([Group::CLIENT_CONFIG_READ])]
    public ?string $maptilerApiKey = null;

    #[Groups([Group::CLIENT_CONFIG_READ])]
    public bool $mapStyleAvailable = false;

    /** @var list<string> */
    #[Groups([Group::CLIENT_CONFIG_READ])]
    public array $availableStyles = [];
}
