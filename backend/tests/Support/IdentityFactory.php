<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Identity;

/**
 * Identity names are server-wide: entity and service tests build one identity per player, naming
 * it exactly as the test uses the display name.
 */
final class IdentityFactory
{
    public const string DEVICE_KEY = 'test-device-key';

    public static function create(string $name, string $deviceKey = self::DEVICE_KEY): Identity
    {
        return new Identity($name, $deviceKey);
    }
}
