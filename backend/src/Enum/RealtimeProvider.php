<?php

declare(strict_types=1);

namespace App\Enum;

enum RealtimeProvider: string
{
    case None = 'none';
    case GtfsRtUrl = 'gtfs_rt_url';
    case SiriLite = 'siri_lite';
}
