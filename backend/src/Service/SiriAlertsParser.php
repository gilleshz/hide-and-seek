<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TrafficAlert;
use App\Enum\AlertSeverity;

/**
 * Maps a SIRI Lite ServiceDelivery GeneralMessageDelivery or SituationExchangeDelivery onto
 * TrafficAlert. HTML message bodies are stripped to plain text; the source tag is
 * "siri_lite:<profile key>".
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
        foreach (self::many($delivery['SituationExchangeDelivery'] ?? null) as $sxDelivery) {
            if (!is_array($sxDelivery)) {
                continue;
            }
            $situations = is_array($sxDelivery['Situations'] ?? null) ? $sxDelivery['Situations'] : [];
            foreach (self::many($situations['PtSituationElement'] ?? null) as $situation) {
                if (!is_array($situation)) {
                    continue;
                }
                $alerts[] = self::situationAlert($situation, $source);
            }
        }

        return $alerts;
    }

    /**
     * @param array<mixed> $situation
     */
    private static function situationAlert(array $situation, string $source): TrafficAlert
    {
        $id = self::ref($situation['SituationNumber'] ?? null);
        if ($id === '') {
            $encoded = json_encode($situation);
            $id = 'sx-' . substr(md5(is_string($encoded) ? $encoded : ''), 0, 16);
        }
        $channel = self::ref($situation['ReasonName'] ?? null);
        $validity = self::many($situation['ValidityPeriod'] ?? null);
        $firstValidity = is_array($validity[0] ?? null) ? $validity[0] : [];
        $affects = is_array($situation['Affects'] ?? null) ? $situation['Affects'] : [];
        $body = $situation['Description'] ?? ($situation['Detail'] ?? null);

        return new TrafficAlert(
            id: $id,
            channel: $channel,
            severity: self::severity(self::ref($situation['Severity'] ?? null), $channel),
            title: self::translationText($situation['Summary'] ?? null),
            body: HtmlText::strip(self::translationText($body)),
            lineRefs: self::affectedLineRefs($affects),
            routeIds: [],
            stopIds: self::affectedStopRefs($affects),
            validFrom: self::timestamp($firstValidity['StartTime'] ?? null),
            validUntil: self::timestamp($firstValidity['EndTime'] ?? null),
            source: $source,
        );
    }

    /**
     * @param array<mixed> $affects
     *
     * @return list<string>
     */
    private static function affectedLineRefs(array $affects): array
    {
        $refs = [];
        $networks = is_array($affects['Networks'] ?? null) ? $affects['Networks'] : [];
        $lines = is_array($affects['Lines'] ?? null) ? $affects['Lines'] : [];
        $holders = [
            ...self::many($networks['AffectedNetwork'] ?? null),
            ...self::many($lines['AffectedLine'] ?? null),
        ];
        foreach ($holders as $holder) {
            $affectedLines = is_array($holder) && isset($holder['AffectedLine'])
                ? self::many($holder['AffectedLine'])
                : (is_array($holder) ? [$holder] : []);
            foreach ($affectedLines as $line) {
                $ref = self::ref(is_array($line) ? ($line['LineRef'] ?? null) : null);
                if ($ref !== '') {
                    $refs[] = $ref;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param array<mixed> $affects
     *
     * @return list<string>
     */
    private static function affectedStopRefs(array $affects): array
    {
        $refs = [];
        $stopPoints = is_array($affects['StopPoints'] ?? null) ? $affects['StopPoints'] : [];
        foreach (self::many($stopPoints['AffectedStopPoint'] ?? null) as $stopPoint) {
            $ref = self::ref(is_array($stopPoint) ? ($stopPoint['StopPointRef'] ?? null) : null);
            if ($ref !== '') {
                $refs[] = $ref;
            }
        }

        return array_values(array_unique($refs));
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
