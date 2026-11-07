<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TrafficAlert;
use App\Enum\AlertSeverity;

/**
 * Maps a SIRI Lite ServiceDelivery GeneralMessageDelivery onto TrafficAlert. HTML message bodies
 * are stripped to plain text; the source tag is "siri_lite:<profile key>".
 */
final class SiriAlertsParser
{
    /**
     * @return list<TrafficAlert>
     */
    public static function parse(string $body, string $source): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }
        $siri = is_array($decoded['Siri'] ?? null) ? $decoded['Siri'] : [];
        $delivery = $siri['ServiceDelivery'] ?? $decoded['ServiceDelivery'] ?? null;
        if (!is_array($delivery)) {
            return [];
        }

        $alerts = [];
        foreach (self::many($delivery['GeneralMessageDelivery'] ?? null) as $messageDelivery) {
            if (!is_array($messageDelivery)) {
                continue;
            }
            foreach (self::many($messageDelivery['InfoMessage'] ?? null) as $infoMessage) {
                if (!is_array($infoMessage)) {
                    continue;
                }
                $alert = self::alert($infoMessage, $source);
                if ($alert !== null) {
                    $alerts[] = $alert;
                }
            }
        }

        return $alerts;
    }

    /**
     * @param array<mixed> $infoMessage
     */
    private static function alert(array $infoMessage, string $source): ?TrafficAlert
    {
        $id = self::ref($infoMessage['InfoMessageIdentifier'] ?? null);
        if ($id === '') {
            return null;
        }
        $channel = self::ref($infoMessage['InfoChannelRef'] ?? null);
        $content = is_array($infoMessage['Content'] ?? null) ? $infoMessage['Content'] : [];

        return new TrafficAlert(
            id: $id,
            channel: $channel,
            severity: self::severity(self::ref($infoMessage['Severity'] ?? null), $channel),
            title: self::messageText($content, 'title'),
            body: HtmlText::strip(self::messageText($content, 'details')),
            lineRefs: self::lineRefs($content['ImpactedLineRef'] ?? null),
            routeIds: [],
            stopIds: [],
            validFrom: self::timestamp($content['ImpactStartDateTime'] ?? null),
            validUntil: self::timestamp($infoMessage['ValidUntilTime'] ?? null),
            source: $source,
        );
    }

    private static function severity(string $severity, string $channel): AlertSeverity
    {
        $normalized = strtolower($severity);
        if ($normalized !== '') {
            return match (true) {
                str_contains($normalized, 'critical'), str_contains($normalized, 'severe') => AlertSeverity::Severe,
                str_contains($normalized, 'warning'), str_contains($normalized, 'major') => AlertSeverity::Warning,
                default => AlertSeverity::Info,
            };
        }
        $channelLower = strtolower($channel);

        return match (true) {
            str_contains($channelLower, 'emergency') => AlertSeverity::Severe,
            str_contains($channelLower, 'disruption'), str_contains($channelLower, 'works') => AlertSeverity::Warning,
            default => AlertSeverity::Info,
        };
    }

    /**
     * The message in the given zone (e.g. "title", "details"), else the first message; the French
     * translation wins when several languages are present.
     *
     * @param array<mixed> $content
     */
    private static function messageText(array $content, string $zone): string
    {
        $fallback = '';
        foreach (self::many($content['Message'] ?? null) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $text = self::translationText($message['MessageText'] ?? null);
            if ($text === '') {
                continue;
            }
            if (strtolower(self::ref($message['MessageZoneRef'] ?? null)) === $zone) {
                return $text;
            }
            $fallback = $fallback === '' ? $text : $fallback;
        }

        return $fallback;
    }

    /**
     * @param mixed $value
     */
    private static function translationText(mixed $value): string
    {
        $fallback = '';
        foreach (self::many($value) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $text = is_string($entry['Value'] ?? null) ? trim($entry['Value']) : '';
            if ($text === '') {
                continue;
            }
            $lang = is_string($entry['Lang'] ?? null) ? strtolower($entry['Lang']) : '';
            if ($lang === 'fr') {
                return $text;
            }
            $fallback = $fallback === '' ? $text : $fallback;
        }

        return $fallback;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function lineRefs(mixed $value): array
    {
        if (is_string($value)) {
            $ref = trim($value);

            return $ref === '' ? [] : [$ref];
        }
        $refs = [];
        foreach (self::many($value) as $entry) {
            $ref = self::ref($entry);
            if ($ref !== '') {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    private static function ref(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_array($value) && is_string($value['value'] ?? null)) {
            return trim($value['value']);
        }

        return '';
    }

    private static function timestamp(mixed $value): ?int
    {
        $text = self::ref($value);
        if ($text === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($text))->getTimestamp();
        } catch (\DateMalformedStringException) {
            return null;
        }
    }

    /**
     * @param mixed $value
     *
     * @return list<mixed>
     */
    private static function many(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }
}
