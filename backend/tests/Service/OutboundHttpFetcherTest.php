<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\FunctionalException;
use App\Service\OutboundHttpFetcher;
use App\Service\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OutboundHttpFetcherTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/fetcher-test-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);
        parent::tearDown();
    }

    #[Test]
    public function itReturnsTheBodyOfA200Response(): void
    {
        $fetcher = $this->fetcher(new MockHttpClient([new MockResponse('payload')]));

        self::assertSame('payload', $fetcher->fetchToString('https://8.8.8.8/feed.pb', 1024, 1.0, 1.0));
    }

    #[Test]
    public function itFollowsAnAbsoluteRedirect(): void
    {
        $client = new MockHttpClient([
            $this->redirect('https://1.1.1.1/final.pb'),
            new MockResponse('final'),
        ]);

        self::assertSame('final', $this->fetcher($client)->fetchToString('https://8.8.8.8/feed.pb', 1024, 1.0, 1.0));
        self::assertSame(2, $client->getRequestsCount());
    }

    /** @return array<string, array{string, string}> */
    public static function relativeLocations(): array
    {
        return [
            'protocol relative' => ['//1.1.1.1/final.pb', 'https://1.1.1.1/final.pb'],
            'root relative' => ['/final.pb', 'https://8.8.8.8/final.pb'],
            'path relative' => ['next.pb', 'https://8.8.8.8/dir/next.pb'],
            'query relative' => ['?token=1', 'https://8.8.8.8/dir/feed.pb?token=1'],
        ];
    }

    #[Test]
    #[DataProvider('relativeLocations')]
    public function itResolvesARelativeLocation(string $location, string $expectedUrl): void
    {
        $requested = [];
        $client = new MockHttpClient(
            function (string $method, string $url) use (&$requested, $location): MockResponse {
                $requested[] = $url;

                return count($requested) === 1
                    ? $this->redirect($location)
                    : new MockResponse('final');
            },
        );

        $body = $this->fetcher($client)->fetchToString('https://8.8.8.8/dir/feed.pb', 1024, 1.0, 1.0);

        self::assertSame('final', $body);
        self::assertSame($expectedUrl, $requested[1] ?? null);
    }

    #[Test]
    public function itRejectsAFragmentOnlyLocation(): void
    {
        $this->expectRuntime(fn () => $this->fetcher(new MockHttpClient([$this->redirect('#frag')]))
            ->fetchToString('https://8.8.8.8/feed.pb', 1024, 1.0, 1.0));
    }

    #[Test]
    public function itShrinksTheTimeoutBudgetAcrossRedirectHops(): void
    {
        $budgets = [];
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$budgets): MockResponse {
                $budgets[] = $options['max_duration'] ?? null;
                usleep(20_000);

                return count($budgets) === 1
                    ? $this->redirect('https://1.1.1.1/final.pb')
                    : new MockResponse('final');
            },
        );

        self::assertSame('final', $this->fetcher($client)->fetchToString('https://8.8.8.8/feed.pb', 1024, 10.0, 5.0));
        self::assertCount(2, $budgets);
        self::assertLessThanOrEqual(5.0, $budgets[0]);
        self::assertLessThan($budgets[0], $budgets[1]);
    }

    #[Test]
    public function itRejectsAnHttpDowngrade(): void
    {
        $client = new MockHttpClient([$this->redirect('http://8.8.8.8/feed.pb')]);

        $this->expectFunctional('gtfs.https_only', fn () => $this->fetcher($client)
            ->fetchToString('https://8.8.8.8/feed.pb', 1024, 1.0, 1.0));
    }

    #[Test]
    public function itRejectsARedirectToAPrivateIp(): void
    {
        $client = new MockHttpClient([$this->redirect('https://10.0.0.1/feed.pb')]);

        $this->expectFunctional('gtfs.private_ip_blocked', fn () => $this->fetcher($client)
            ->fetchToString('https://8.8.8.8/feed.pb', 1024, 1.0, 1.0));
    }

    #[Test]
    public function itRejectsAChainOfMoreThanFiveRedirects(): void
    {
        $responses = [];
        for ($hop = 1; $hop <= 6; ++$hop) {
            $responses[] = $this->redirect('https://8.8.8.8/hop-' . $hop);
        }

        $this->expectRuntime(fn () => $this->fetcher(new MockHttpClient($responses))
            ->fetchToString('https://8.8.8.8/feed.pb', 1024, 1.0, 1.0));
    }

    #[Test]
    public function itRejectsARedirectWithoutALocationHeader(): void
    {
        $this->expectRuntime(fn () => $this->fetcher(new MockHttpClient([new MockResponse('', ['http_code' => 302])]))
            ->fetchToString('https://8.8.8.8/feed.pb', 1024, 1.0, 1.0));
    }

    #[Test]
    public function itRejectsANonSuccessStatus(): void
    {
        $this->expectRuntime(fn () => $this->fetcher(new MockHttpClient([new MockResponse('', ['http_code' => 404])]))
            ->fetchToString('https://8.8.8.8/feed.pb', 1024, 1.0, 1.0));
    }

    #[Test]
    public function itRejectsAStringBodyBeyondTheSizeCap(): void
    {
        $fetcher = $this->fetcher(new MockHttpClient([new MockResponse('0123456789X')]));

        try {
            $fetcher->fetchToString('https://8.8.8.8/feed.pb', 10, 1.0, 1.0);
            self::fail('Expected a LengthException for an oversized body.');
        } catch (\LengthException $e) {
            self::assertStringNotContainsString('8.8.8.8', $e->getMessage());
        }
    }

    #[Test]
    public function itRejectsAFileBodyBeyondTheSizeCapAndDeletesTheDestination(): void
    {
        $destination = $this->workDir . '/feed.zip';
        $fetcher = $this->fetcher(new MockHttpClient([new MockResponse('0123456789X')]));

        try {
            $fetcher->fetchToFile('https://8.8.8.8/feed.pb', $destination, 10, 1.0, 1.0);
            self::fail('Expected a LengthException for an oversized body.');
        } catch (\LengthException) {
            self::assertFileDoesNotExist($destination);
        }
    }

    #[Test]
    public function itDeletesTheDestinationWhenTheDownloadFails(): void
    {
        $destination = $this->workDir . '/feed.zip';
        $fetcher = $this->fetcher(new MockHttpClient([new MockResponse('', ['http_code' => 500])]));

        try {
            $fetcher->fetchToFile('https://8.8.8.8/feed.pb', $destination, 1024, 1.0, 1.0);
            self::fail('Expected a RuntimeException for the failed download.');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($destination);
        }
    }

    #[Test]
    public function itLogsOnlyTheHostWhenAFetchFails(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array<mixed>> */
            public array $contexts = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };
        $fetcher = $this->fetcher(
            new MockHttpClient([new MockResponse('', ['http_code' => 500])]),
            $logger,
        );

        try {
            $fetcher->fetchToString('https://8.8.8.8/feed.zip?token=secret', 1024, 1.0, 1.0);
            self::fail('Expected a RuntimeException for the failed request.');
        } catch (\RuntimeException) {
        }

        self::assertNotSame([], $logger->contexts);
        self::assertSame('8.8.8.8', $logger->contexts[0]['host'] ?? null);
        self::assertArrayNotHasKey('url', $logger->contexts[0]);
        self::assertStringNotContainsString('secret', (string) json_encode($logger->contexts[0]));
    }

    #[Test]
    public function itPinsEveryHopToTheGuardedAddress(): void
    {
        $captured = [];
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured[] = $options['resolve'] ?? null;

                return count($captured) === 1
                    ? $this->redirect('https://1.1.1.1/final.pb')
                    : new MockResponse('final');
            },
        );

        self::assertSame('final', $this->fetcher($client)->fetchToString('https://8.8.8.8/feed.pb', 1024, 1.0, 1.0));
        self::assertSame([['8.8.8.8' => '8.8.8.8'], ['1.1.1.1' => '1.1.1.1']], $captured);
    }

    #[Test]
    public function itReturnsTheContentLengthOfAHeadResponse(): void
    {
        $methods = [];
        $client = new MockHttpClient(function (string $method) use (&$methods): MockResponse {
            $methods[] = $method;

            return new MockResponse('', ['response_headers' => ['Content-Length' => '1234']]);
        });

        self::assertSame(1234, $this->fetcher($client)->headContentLength('https://8.8.8.8/feed.zip', 1.0, 1.0));
        self::assertSame(['HEAD'], $methods);
    }

    #[Test]
    public function itFollowsARedirectForAHeadRequest(): void
    {
        $methods = [];
        $client = new MockHttpClient(function (string $method) use (&$methods): MockResponse {
            $methods[] = $method;

            return count($methods) === 1
                ? $this->redirect('https://1.1.1.1/final.zip')
                : new MockResponse('', ['response_headers' => ['Content-Length' => '4321']]);
        });

        self::assertSame(4321, $this->fetcher($client)->headContentLength('https://8.8.8.8/feed.zip', 1.0, 1.0));
        self::assertSame(['HEAD', 'HEAD'], $methods);
    }

    #[Test]
    public function itReturnsNullWhenTheHeadResponseHasNoContentLength(): void
    {
        self::assertNull($this->fetcher(new MockHttpClient([new MockResponse('')]))
            ->headContentLength('https://8.8.8.8/feed.zip', 1.0, 1.0));
    }

    #[Test]
    public function itRejectsAHeadRedirectToAPrivateIp(): void
    {
        $client = new MockHttpClient([$this->redirect('https://10.0.0.1/feed.zip')]);

        $this->expectFunctional('gtfs.private_ip_blocked', fn () => $this->fetcher($client)
            ->headContentLength('https://8.8.8.8/feed.zip', 1.0, 1.0));
    }

    #[Test]
    public function itRejectsANonSuccessHeadStatus(): void
    {
        $this->expectRuntime(fn () => $this->fetcher(new MockHttpClient([new MockResponse('', ['http_code' => 404])]))
            ->headContentLength('https://8.8.8.8/feed.zip', 1.0, 1.0));
    }

    private function fetcher(MockHttpClient $client, ?LoggerInterface $logger = null): OutboundHttpFetcher
    {
        return new OutboundHttpFetcher($client, new OutboundUrlGuard(), $logger ?? new NullLogger());
    }

    private function redirect(string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => $location]]);
    }

    private function expectFunctional(string $errorKey, callable $call): void
    {
        try {
            $call();
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame($errorKey, $e->getErrorKey());
        }
    }

    private function expectRuntime(callable $call): void
    {
        try {
            $call();
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('8.8.8.8', $e->getMessage());
        }
    }
}
