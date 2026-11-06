<?php

declare(strict_types=1);

namespace App\Service;

final class SiriRef
{
    public static function code(string $ref): string
    {
        $segments = array_values(array_filter(
            explode(':', trim($ref)),
            static fn (string $segment): bool => $segment !== '',
        ));

        return $segments === [] ? '' : $segments[array_key_last($segments)];
    }

    public static function normalized(string $ref): string
    {
        return mb_strtolower(self::code($ref));
    }
}
