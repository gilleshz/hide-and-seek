<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Identity;

/**
 * Identity names are server-wide: entity and service tests build one identity per player, naming
 * it exactly as the test uses the display name. The device key is derived from the name so two
 * fixtures never collide on the unique lookup hash.
 */
final class IdentityFactory
{
    /** Must match .env.test / phpunit.dist.xml IDENTITY_LOOKUP_KEY. */
    public const string LOOKUP_KEY = 'test-identity-lookup-key';

    public static function deviceKey(string $name): string
    {
        return hash('sha256', 'test-device:' . $name);
    }

    public static function lookupHash(string $deviceKey): string
    {
        return hash_hmac('sha256', $deviceKey, self::LOOKUP_KEY);
    }

    public static function create(string $name, ?string $deviceKey = null): Identity
    {
        $deviceKey ??= self::deviceKey($name);

        return new Identity($name, $deviceKey, self::lookupHash($deviceKey));
    }
}
