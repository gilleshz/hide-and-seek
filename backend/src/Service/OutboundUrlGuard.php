<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\FunctionalException;

/**
 * Shared egress guard for feeds fetched from a host-supplied URL.
 *
 * Static GTFS downloads are resolved and pinned before the request; GTFS-RT
 * fetches do the same on every poll so a DNS change cannot redirect them inward.
 */
final class OutboundUrlGuard
{
    // RFC 1918, link-local, CGNAT and reserved ranges; checked against the ip2long bits, not a prefix string.
    private const array BLOCKED_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** @return array{host: string, ip: string} */
    public function resolve(string $url): array
    {
        $host = $this->assertShape($url)['host'];
        $resolvedIp = gethostbyname($host);
        if ($resolvedIp === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            throw new FunctionalException(
                message: 'Could not resolve host for GTFS download.',
                errorKey: 'gtfs.host_resolution_failed',
            );
        }

        if ($this->isBlockedIp($resolvedIp)) {
            throw new FunctionalException(
                message: 'Downloads from private or reserved IP ranges are not allowed.',
                errorKey: 'gtfs.private_ip_blocked',
            );
        }

        return ['host' => $host, 'ip' => $resolvedIp];
    }

    /** @return array{host: string} */
    public function assertShape(string $url): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            throw new FunctionalException(message: 'Invalid URL: missing scheme.', errorKey: 'gtfs.invalid_url_scheme');
        }
        if (strtolower($scheme) !== 'https') {
            throw new FunctionalException(
                message: 'Only HTTPS URLs are accepted for GTFS downloads.',
                errorKey: 'gtfs.https_only',
            );
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new FunctionalException(message: 'Invalid URL: missing host.', errorKey: 'gtfs.invalid_url_host');
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            throw new FunctionalException(
                message: 'IPv6 GTFS hosts are not supported.',
                errorKey: 'gtfs.ipv6_not_supported',
            );
        }
        if (str_contains($host, ':')) {
            $host = '[' . trim($host, '[]') . ']';
        }

        return ['host' => $host];
    }

    private function isBlockedIp(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            [$base, $prefixLength] = explode('/', $cidr);
            $mask = (0xFFFFFFFF << 32 - (int) $prefixLength) & 0xFFFFFFFF;
            $baseLong = ip2long($base);
            if ($baseLong !== false && ($long & $mask) === ($baseLong & $mask)) {
                return true;
            }
        }

        return false;
    }
}
