<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Dto\GtfsRealtimeProbeInput;
use App\Serializer\Group;
use App\State\GtfsRealtimeProbeProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'GtfsRealtimeProbe',
    operations: [
        new Post(
            uriTemplate: '/gtfs-sources/realtime-probe',
            input: GtfsRealtimeProbeInput::class,
            processor: GtfsRealtimeProbeProcessor::class,
            status: 200,
        ),
    ],
    normalizationContext: ['groups' => [Group::GTFS_REALTIME_PROBE_READ]],
    denormalizationContext: ['groups' => [Group::GTFS_REALTIME_PROBE_WRITE]],
)]
final class GtfsRealtimeProbeResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::GTFS_REALTIME_PROBE_READ])]
    public string $id = 'probe';

    #[Groups([Group::GTFS_REALTIME_PROBE_READ])]
    public string $status = 'invalid';

    #[Groups([Group::GTFS_REALTIME_PROBE_READ])]
    public ?string $feedType = null;

    #[Groups([Group::GTFS_REALTIME_PROBE_READ])]
    public ?int $headerAgeSeconds = null;

    #[Groups([Group::GTFS_REALTIME_PROBE_READ])]
    public int $tripCount = 0;
}
