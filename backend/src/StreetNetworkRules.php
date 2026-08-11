<?php

declare(strict_types=1);

namespace App;

final class StreetNetworkRules
{
    /** A street just past the zone edge is still a legitimate trace target, so the bbox reaches past it. */
    public const float BBOX_MARGIN_METERS = 150.0;

    public const int OVERPASS_TIMEOUT_SECONDS = 90;

    /** The trim peaks at roughly 9x the raw answer, so 16 MiB keeps that under a gigabyte on a dense zone. */
    public const int MAX_RESPONSE_BYTES = 16_777_216;

    public const int MAX_PAYLOAD_BYTES = 6_291_456;

    /** ~1.1 m of precision, which halves the payload and makes coincident nodes of two ways compare equal. */
    public const int COORDINATE_DECIMALS = 5;

    public const int MAX_WARM_ATTEMPTS = 3;

    public const int WARM_BATCH_LIMIT = 5;

    public const float CENTER_TOLERANCE_METERS = 1.0;
}
