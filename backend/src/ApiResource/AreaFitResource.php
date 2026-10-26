<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Dto\AreaFitArea;
use App\Dto\AreaFitInput;
use App\Serializer\Group;
use App\State\AreaFitProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'AreaFit',
    operations: [
        new Post(
            uriTemplate: '/area-fit',
            input: AreaFitInput::class,
            processor: AreaFitProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::AREA_FIT_READ]],
    denormalizationContext: ['groups' => [Group::AREA_FIT_WRITE]],
)]
final class AreaFitResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::AREA_FIT_READ])]
    public string $id = 'fit';

    /** @var list<AreaFitArea> */
    #[Groups([Group::AREA_FIT_READ])]
    public array $areas = [];
}
