<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\OverpassEmptyPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class OverpassHttpClient
{
    private const int MAX_RETRIES = 5;
    private const int INITIAL_DELAY_MS = 1000;

    /** @var list<string> */
    private array $mirrors;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%app.overpass_mirrors%')]
        string $overpassMirrors,
        #[Autowire('%app.overpass_mirrors_randomize%')]
        private bool $randomize,
        private ?LoggerInterface $logger = null,
    ) {
        $this->mirrors = $this->parseMirrors($overpassMirrors);
    }

    /**
     * @return list<string>
     */
    private function parseMirrors(string $raw): array
    {
        $trimmed = array_map('trim', explode(',', $raw));
        $filtered = array_values(array_filter($trimmed, static fn (string $s): bool => $s !== ''));

        if ($filtered === []) {
            throw new \RuntimeException(
                'OVERPASS_MIRRORS environment variable is empty.'
                . ' Set it to a comma-separated list of Overpass API endpoints.',
            );
        }

        return $filtered;
    }

    /**
     * Random picks without replacement deprioritise slow mirrors; round-robin otherwise.
     * See OverpassEmptyPolicy for how an empty answer is treated.
     */
    public function fetch(
        string $query,
        int $timeout,
        int $maxResponseBytes,
        OverpassEmptyPolicy $emptyPolicy = OverpassEmptyPolicy::Allow,
    ): string {
        $remaining = $this->mirrors;
        $lastException = null;
        $delay = self::INITIAL_DELAY_MS;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; ++$attempt) {
            $mirror = $this->pickMirror($remaining, $attempt);

            try {
                if ($attempt > 0) {
                    $jitter = mt_rand(0, (int) ($delay / 2));
                    usleep(($delay + $jitter) * 1000);
                    $delay *= 2;
                }

                $response = $this->httpClient->request('POST', $mirror, [
                    'body' => ['data' => $query],
                    'timeout' => $timeout,
                    'max_redirects' => 0,
                ]);

                $body = $this->readCapped($response, $maxResponseBytes);

                if (!str_starts_with(trim($body), '{')) {
                    throw new \RuntimeException('Overpass mirror returned non-JSON response.');
                }

                if ($emptyPolicy !== OverpassEmptyPolicy::Allow && $this->isEmptyErrorRemark($body)) {
                    throw new \RuntimeException('Overpass mirror returned an error remark with no data.');
                }

                if ($emptyPolicy === OverpassEmptyPolicy::RejectAny && $this->hasNoElements($body)) {
                    throw new \RuntimeException('Overpass mirror returned no elements at all.');
                }

                return $body;
            } catch (TransportExceptionInterface | HttpExceptionInterface | \RuntimeException $e) {
                $lastException = $e;
            }
        }

        throw new \RuntimeException(
            'Failed to fetch Overpass data after ' . self::MAX_RETRIES . ' attempts.',
            0,
            $lastException,
        );
    }

    /**
     * @param array<int, string> $remaining
     */
    private function pickMirror(array &$remaining, int $attempt): string
    {
        if ($this->randomize) {
            if ($remaining === []) {
                $remaining = $this->mirrors;
            }
            $index = array_rand($remaining);
            $mirror = $remaining[$index];
            unset($remaining[$index]);
            $remaining = array_values($remaining);

            return $mirror;
        }

        return $this->mirrors[$attempt % count($this->mirrors)];
    }

    private function isEmptyErrorRemark(string $body): bool
    {
        if (!str_contains($body, '"remark"')) {
            return false;
        }

        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        $remark = is_array($data) ? ($data['remark'] ?? null) : null;
        $elements = is_array($data) ? ($data['elements'] ?? null) : null;

        return is_string($remark) && $remark !== '' && $elements === [];
    }

    /** Matched textually: decoding a multi-MB answer here would cost several times its size in memory. */
    private function hasNoElements(string $body): bool
    {
        return !str_contains($body, '"elements"')
            || preg_match('/"elements"\s*:\s*\[\s*\]/', $body) === 1;
    }

    private function readCapped(ResponseInterface $response, int $maxResponseBytes): string
    {
        $buffer = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $buffer .= $chunk->getContent();
            if (strlen($buffer) > $maxResponseBytes) {
                $this->logger?->warning('Overpass response exceeded size cap ({cap} bytes), degrading.', [
                    'cap' => $maxResponseBytes,
                ]);
                throw new \RuntimeException('Overpass response exceeded the size cap.');
            }
        }

        return $buffer;
    }
}
