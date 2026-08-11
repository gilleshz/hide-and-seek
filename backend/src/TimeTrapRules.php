<?php

declare(strict_types=1);

namespace App;

final class TimeTrapRules
{
    public const int SNAP_RADIUS_METERS = 150;

    public const int TRIP_RADIUS_METERS = 50;

    public const int REDETECT_COOLDOWN_MINUTES = 10;

    /**
     * A chord this long never described a real journey (e.g. underground rides),
     * so the segment test falls back to the latest point.
     */
    public const int MAX_SEGMENT_SECONDS = 60;

    public const int MAX_TRAPS_PER_ROUND = 8;
}
