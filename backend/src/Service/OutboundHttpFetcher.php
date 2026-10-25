<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\FunctionalException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches a host-supplied URL, applying the egress guard to every redirect hop.
 *
 * Redirects are followed manually with `max_redirects` disabled, so each hop is
 * shape-checked, resolved and pinned to the blocklist-checked IP. Exception
 * messages stay generic: hosts, IPs and transport details are only logged.
 */
final readonly class OutboundHttpFetcher
{
    private const int MAX_REDIRECTS = 5;

    public function __construct(
        private HttpClientInterface $httpClient,
        private OutboundUrlGuard $urlGuard,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public function fetchToString(
        string $url,
        int $maxBytes,
        float $timeoutSeconds,
        float $maxDurationSeconds,
        array $headers = [],
    ): string {
        $content = '';
        $this->stream(
            $url,
            $maxBytes,
            $timeoutSeconds,
            $maxDurationSeconds,
            $headers,
            static function (string $chunk) use (&$content): void {
                $content .= $chunk;
            },
        );

        return $content;
    }

    /**
     * @param array<string, string> $headers
     */
    public function fetchToFile(
        string $url,
        string $destination,
        int $maxBytes,
        float $timeoutSeconds,
        float $maxDurationSeconds,
        array $headers = [],
    ): void {
        $handle = fopen($destination, 'wb');
        if ($handle === false) {
            @unlink($destination);

            throw new \RuntimeException('Failed to open the destination file.');
        }

        try {
            $this->stream(
                $url,
                $maxBytes,
                $timeoutSeconds,
                $maxDurationSeconds,
                $headers,
                static function (string $chunk) use ($handle): void {
                    if ($chunk !== '' && fwrite($handle, $chunk) !== strlen($chunk)) {
                        throw new \RuntimeException('Failed to write the response to disk.');
                    }
                },
            );
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($destination);

            throw $e;
        }

        fclose($handle);
    }

    /**
     * Returns the final Content-Length of a HEAD response, or null when it is absent.
     *
     * Non-2xx responses throw, matching the GET paths; callers that want a best-effort
     * size catch and keep the feed usable without one.
     */
    public function headContentLength(
        string $url,
        float $timeoutSeconds = 10.0,
        ?float $maxDurationSeconds = null,
    ): ?int {
        try {
            $response = $this->request(
                'HEAD',
                $url,
                $timeoutSeconds,
                $maxDurationSeconds ?? $timeoutSeconds,
                [],
            );
            $length = $response->getHeaders(false)['content-length'][0] ?? null;
        } catch (FunctionalException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('Outbound HEAD failed for {host}: {error}', [
                'host' => $this->hostOf($url),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw new \RuntimeException('Outbound request failed.', 0, $e);
        }

        if (!is_string($length) || preg_match('/^\d+$/', $length) !== 1) {
            return null;
        }

        return (int) $length;
    }

    /**
     * @param array<string, string> $headers
     * @param callable(string): void $sink
     */
    private function stream(
        string $url,
        int $maxBytes,
        float $timeoutSeconds,
        float $maxDurationSeconds,
        array $headers,
        callable $sink,
    ): void {
        try {
            $response = $this->request('GET', $url, $timeoutSeconds, $maxDurationSeconds, $headers);
            $received = 0;
            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new \RuntimeException('Outbound request timed out.');
                }
                $data = $chunk->getContent();
                $received += strlen($data);
                if ($received > $maxBytes) {
                    throw new \LengthException('Response exceeds the maximum allowed size.');
                }
                $sink($data);
            }
        } catch (FunctionalException | \LengthException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('Outbound fetch failed for {host}: {error}', [
                'host' => $this->hostOf($url),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw new \RuntimeException('Outbound request failed.', 0, $e);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $url,
        float $timeoutSeconds,
        float $maxDurationSeconds,
        array $headers,
    ): ResponseInterface {
        $deadline = microtime(true) + $maxDurationSeconds;
        $current = $url;
        for ($redirects = 0;; ++$redirects) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                throw new \RuntimeException('Outbound request timed out.');
            }
            $pinned = $this->urlGuard->resolve($current);
            $response = $this->httpClient->request($method, $current, [
                'timeout' => min($timeoutSeconds, $remaining),
                'max_duration' => $remaining,
                'max_redirects' => 0,
                'resolve' => [$pinned['host'] => $pinned['ip']],
                'headers' => $headers,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return $response;
            }
            if ($status < 300 || $status >= 400) {
                throw new \RuntimeException('Outbound request returned HTTP ' . $status . '.');
            }
            if ($redirects >= self::MAX_REDIRECTS) {
                throw new \RuntimeException('Outbound request exceeded the maximum number of redirects.');
            }

            $current = $this->absoluteUrl($current, $this->locationOf($response));
        }
    }

    private function locationOf(ResponseInterface $response): string
    {
        $location = $response->getHeaders(false)['location'][0] ?? '';
        if ($location === '') {
            throw new \RuntimeException('Outbound redirect response has no Location header.');
        }

        return $location;
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('Cannot resolve the redirect target.');
        }

        $scheme = strtolower($parts['scheme']);
        $authority = $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $authority . $location;
        }
        if (str_starts_with($location, '#')) {
            throw new \RuntimeException('Outbound redirect target has no location.');
        }

        $path = is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';
        if (str_starts_with($location, '?')) {
            return $scheme . '://' . $authority . $path . $location;
        }

        $slash = strrpos($path, '/');
        $directory = $slash === false ? '' : substr($path, 0, $slash + 1);

        return $scheme . '://' . $authority . $directory . $location;
    }

    private function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : 'unknown';
    }
}
