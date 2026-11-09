<?php

declare(strict_types=1);

namespace App\Service;

final class SiriRef
{
    /**
     * The identifying segment of a SIRI ref. Refs wrap the code in a namespace and kind, e.g.
     * STIF:StopPoint:Q:12345:, SYTRAL:Line::C1:, ActIV:Line::C1:SYTRAL; the code follows the kind
     * segment (Line/StopPoint, skipping a Q qualifier). Bare ids are their own code.
     */
    public static function code(string $ref): string
    {
        $segments = array_values(array_filter(
            explode(':', trim($ref)),
            static fn (string $segment): bool => $segment !== '',
        ));
        if ($segments === []) {
            return '';
        }
        $lower = array_map(strtolower(...), $segments);
        $kindIndex = array_search('line', $lower, true);
        if ($kindIndex === false) {
            $kindIndex = array_search('stoppoint', $lower, true);
        }
        if ($kindIndex !== false) {
            $next = $kindIndex + 1;
            if (isset($lower[$next]) && $lower[$next] === 'q') {
                $next++;
            }
            if (isset($segments[$next])) {
                return $segments[$next];
            }
        }

        return $segments[array_key_last($segments)];
    }

    public static function normalized(string $ref): string
    {
        return mb_strtolower(self::code($ref));
    }

    /**
     * Case- and accent-insensitive form, for matching operator line refs against GTFS short names.
     */
    public static function normalizedFolded(string $ref): string
    {
        $code = self::code($ref);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $code);

        return mb_strtolower($ascii === false ? $code : $ascii);
    }
}
