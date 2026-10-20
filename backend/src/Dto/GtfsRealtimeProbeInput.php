<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class GtfsRealtimeProbeInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 1000, normalizer: 'trim')]
    #[Groups([Group::GTFS_REALTIME_PROBE_WRITE])]
    public string $url = '';
}
