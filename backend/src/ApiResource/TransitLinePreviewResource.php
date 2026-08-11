<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Dto\TransitLinePreviewInput;
use App\Serializer\Group;
use App\State\TransitLinePreviewProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'TransitLinePreview',
    operations: [
        new Post(
            uriTemplate: '/transit-line-preview',
            input: TransitLinePreviewInput::class,
            processor: TransitLinePreviewProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::TRANSIT_LINE_PREVIEW_READ]],
    denormalizationContext: ['groups' => [Group::TRANSIT_LINE_PREVIEW_WRITE]],
)]
final class TransitLinePreviewResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::TRANSIT_LINE_PREVIEW_READ])]
    public string $id = 'preview';

    #[Groups([Group::TRANSIT_LINE_PREVIEW_READ])]
    public string $geoJson;
}
