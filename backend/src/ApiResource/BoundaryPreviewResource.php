<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Dto\BoundaryPreviewInput;
use App\Serializer\Group;
use App\State\BoundaryPreviewProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'BoundaryPreview',
    operations: [
        new Post(
            uriTemplate: '/boundary-preview',
            input: BoundaryPreviewInput::class,
            processor: BoundaryPreviewProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::BOUNDARY_PREVIEW_READ]],
    denormalizationContext: ['groups' => [Group::BOUNDARY_PREVIEW_WRITE]],
)]
final class BoundaryPreviewResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::BOUNDARY_PREVIEW_READ])]
    public string $id = 'preview';

    #[Groups([Group::BOUNDARY_PREVIEW_READ])]
    public string $geoJson;
}
