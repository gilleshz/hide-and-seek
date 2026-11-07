<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\SiriService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Operator details for SIRI Lite live data. Profiles live in `app.siri_profiles`; a profile with
 * blank credentials stays configured but is unavailable, so it is never offered or fetched.
 */
final readonly class SiriProfileRegistry
{
    /** @var array<string, SiriProfile> */
    private array $profiles;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        #[Autowire('%app.siri_profiles%')]
        array $config,
    ) {
        $profiles = [];
        foreach ($config as $key => $raw) {
            $profile = $this->build((string) $key, $raw);
            $profiles[$profile->key] = $profile;
        }

        $this->profiles = $profiles;
    }

    /** @return list<SiriProfile> */
    public function available(): array
    {
        return array_values(array_filter(
            $this->profiles,
            static fn (SiriProfile $profile): bool => $profile->available,
        ));
    }

    public function find(string $key): ?SiriProfile
    {
        return $this->profiles[$key] ?? null;
    }

    /**
     * Catalog magic: the first available profile whose alias appears in the feed's provider or
     * name and, when both the profile and the row name a city, whose city covers the row's
     * municipality. A feed without a municipality still matches on the alias alone.
     */
    public function match(string $provider, string $name, string $municipality): ?SiriProfile
    {
        $aliases = array_values(array_filter([
            $this->normalize($provider),
            $this->normalize($name),
        ]));
        $city = $this->normalize($municipality);
        if ($aliases === []) {
            return null;
        }

        foreach ($this->available() as $profile) {
            if (!$this->matchesAny($profile->aliases, $aliases)) {
                continue;
            }
            if (
                !$profile->cityOptional
                && $profile->cities !== []
                && $city !== ''
                && !$this->matchesAny($profile->cities, [$city])
            ) {
                continue;
            }

            return $profile;
        }

        return null;
    }

    private function build(string $key, mixed $raw): SiriProfile
    {
        if ($key === '') {
            throw new \InvalidArgumentException('SIRI profile config entries need a key.');
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException("SIRI profile '{$key}' must be a mapping.");
        }

        $service = SiriService::tryFrom($this->text($raw['service'] ?? null));
        if ($service === null) {
            throw new \InvalidArgumentException("SIRI profile '{$key}' has an unknown service.");
        }

        $endpoint = $this->text($raw['endpoint'] ?? null);
        if (!str_starts_with($endpoint, 'https://')) {
            throw new \InvalidArgumentException("SIRI profile '{$key}' needs an HTTPS endpoint.");
        }

        [$authHeaders, $available] = $this->auth($key, $raw['auth'] ?? null);

        $alertsEndpoint = $this->nullableText($raw['alerts_endpoint'] ?? null);
        if ($alertsEndpoint !== null && !str_starts_with($alertsEndpoint, 'https://')) {
            throw new \InvalidArgumentException("SIRI profile '{$key}' needs an HTTPS alerts endpoint.");
        }

        return new SiriProfile(
            key: $key,
            label: $this->text($raw['label'] ?? null) ?: $key,
            service: $service,
            endpoint: $endpoint,
            authHeaders: $authHeaders,
            available: $available,
            stopRefTemplate: $this->nullableText($raw['stop_ref'] ?? null),
            lineRefTemplate: $this->nullableText($raw['line_ref'] ?? null),
            query: $this->stringMap($raw['query'] ?? null),
            aliases: $this->stringList($raw['aliases'] ?? null),
            cities: $this->stringList($raw['cities'] ?? null),
            cityOptional: ($raw['city_optional'] ?? false) === true,
            alertsEndpoint: $alertsEndpoint,
        );
    }

    /**
     * @return array{array<string, string>, bool} headers and availability
     */
    private function auth(string $key, mixed $raw): array
    {
        if ($raw !== null && !is_array($raw)) {
            throw new \InvalidArgumentException("SIRI profile '{$key}' auth must be a mapping.");
        }
        $auth = is_array($raw) ? $raw : [];
        $kind = $this->text($auth['kind'] ?? null);
        if ($kind === '') {
            if ($auth !== []) {
                throw new \InvalidArgumentException("SIRI profile '{$key}' auth needs a kind.");
            }

            return [[], true];
        }

        if ($kind === 'none') {
            return [[], true];
        }

        if ($kind === 'header') {
            $name = $this->text($auth['name'] ?? null);
            $value = $this->text($auth['value'] ?? null);
            if ($name === '') {
                throw new \InvalidArgumentException("SIRI profile '{$key}' header auth needs a name.");
            }

            return [$value === '' ? [] : [$name => $value], $value !== ''];
        }

        if ($kind === 'basic') {
            $user = $this->text($auth['user'] ?? null);
            $password = $this->text($auth['password'] ?? null);
            $passwordRequired = ($auth['password_required'] ?? false) === true;
            if ($user === '' || ($passwordRequired && $password === '')) {
                return [[], false];
            }

            return [['Authorization' => 'Basic ' . base64_encode($user . ':' . $password)], true];
        }

        throw new \InvalidArgumentException("SIRI profile '{$key}' has an unknown auth kind.");
    }

    /**
     * @param list<string> $candidates
     * @param list<string> $haystacks
     */
    private function matchesAny(array $candidates, array $haystacks): bool
    {
        foreach ($candidates as $candidate) {
            $needle = $this->normalize($candidate);
            if ($needle === '') {
                continue;
            }
            foreach ($haystacks as $haystack) {
                if (str_contains(' ' . $haystack . ' ', ' ' . $needle . ' ')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = strtolower($ascii === false ? $value : $ascii);
        $parts = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? implode(' ', $parts) : '';
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function nullableText(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === '' ? null : $text;
    }

    /** @return array<string, string> */
    private function stringMap(mixed $value): array
    {
        $map = [];
        if (is_array($value)) {
            foreach ($value as $key => $entry) {
                if (is_string($entry) && is_string($key) && $key !== '') {
                    $map[$key] = trim($entry);
                }
            }
        }

        return $map;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        $list = [];
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $list[] = trim($entry);
                }
            }
        }

        return $list;
    }
}
