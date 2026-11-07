<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TrafficAlert;
use App\Enum\AlertSeverity;

/**
 * Maps the decoded GTFS-RT feed (see bin/gtfs_rt_decode.py) alert entities onto TrafficAlert.
 * Translated strings pick the French translation when present, else the first one.
 */
final class GtfsRtAlertsParser
{
    /**
     * @param array<mixed> $decoded
     *
     * @return list<TrafficAlert>
     */
    public static function parse(array $decoded): array
    {
        $rawAlerts = $decoded['alerts'] ?? null;
        if (!is_array($rawAlerts)) {
            return [];
        }

        $alerts = [];
        foreach ($rawAlerts as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $id = is_string($raw['id'] ?? null) ? trim($raw['id']) : '';
            if ($id === '') {
                continue;
            }
            $alerts[] = new TrafficAlert(
                id: $id,
                channel: self::text($raw['cause'] ?? null),
                severity: self::severity(is_string($raw['effect'] ?? null) ? $raw['effect'] : ''),
                title: self::translation($raw['headerText'] ?? null),
                body: self::translation($raw['descriptionText'] ?? null),
                lineRefs: [],
                routeIds: self::strings($raw['routeIds'] ?? null),
                stopIds: self::strings($raw['stopIds'] ?? null),
                validFrom: self::intOrNull($raw['validFrom'] ?? null),
                validUntil: self::intOrNull($raw['validUntil'] ?? null),
                source: 'gtfs_rt',
            );
        }

        return $alerts;
    }

    private static function severity(string $effect): AlertSeverity
    {
        return match ($effect) {
            'no_service', 'stop_moved' => AlertSeverity::Severe,
            'reduced_service', 'significant_delays', 'detour', 'modified_service' => AlertSeverity::Warning,
            default => AlertSeverity::Info,
        };
    }

    /**
     * @param mixed $translations
     */
    private static function translation(mixed $translations): string
    {
        if (!is_array($translations)) {
            return '';
        }
        $first = '';
        foreach ($translations as $translation) {
            if (!is_array($translation)) {
                continue;
            }
            $value = is_string($translation['value'] ?? null) ? trim($translation['value']) : '';
            if ($value === '') {
                continue;
            }
            if (strtolower(is_string($translation['lang'] ?? null) ? $translation['lang'] : '') === 'fr') {
                return $value;
            }
            $first = $first === '' ? $value : $first;
        }

        return $first;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        $strings = [];
        if (!is_array($value)) {
            return $strings;
        }
        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $strings[] = trim($entry);
            }
        }

        return $strings;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
