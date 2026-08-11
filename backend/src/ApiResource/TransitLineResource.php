<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Dto\TransitLineDiscoveryInput;
use App\Serializer\Group;
use App\State\TransitLineDiscoveryProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'TransitLine',
    operations: [
        new Post(
            uriTemplate: '/transit-lines',
            input: TransitLineDiscoveryInput::class,
            processor: TransitLineDiscoveryProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::TRANSIT_LINE_READ]],
    denormalizationContext: ['groups' => [Group::TRANSIT_LINE_DISCOVERY_WRITE]],
)]
final class TransitLineResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::TRANSIT_LINE_READ])]
    public string $osmId;

    #[Groups([Group::TRANSIT_LINE_READ])]
    public string $osmType;

    #[Groups([Group::TRANSIT_LINE_READ])]
    public string $ref;

    #[Groups([Group::TRANSIT_LINE_READ])]
    public string $name;

    #[Groups([Group::TRANSIT_LINE_READ])]
    public string $nameEn;

    #[Groups([Group::TRANSIT_LINE_READ])]
    public string $colour;

    #[Groups([Group::TRANSIT_LINE_READ])]
    public string $routeType;

    #[Groups([Group::TRANSIT_LINE_READ])]
    public string $network;

    #[Groups([Group::TRANSIT_LINE_READ])]
    public string $operator;
}
