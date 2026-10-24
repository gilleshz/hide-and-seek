<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\OutboundHttpFetcher;
use App\Service\OutboundUrlGuard;
use App\Service\TransitRealtimeService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;

final class TransitRealtimeServiceTest extends TestCase
{
    #[Test]
    public function itReportsAFreshTripUpdateFeed(): void
    {
        $result = $this->service([
            'type' => 'trip_update',
            'headerTimestamp' => time() - 30,
            'trips' => ['T1' => [], 'T2' => []],
        ])->probe('https://1.1.1.1/feed.pb');

        self::assertSame('ok', $result['status']);
        self::assertSame('trip_update', $result['feedType']);
        self::assertSame(2, $result['tripCount']);
        self::assertIsInt($result['headerAgeSeconds']);
        self::assertGreaterThanOrEqual(30, $result['headerAgeSeconds']);
    }

    #[Test]
    public function itReportsAStaleTripUpdateFeed(): void
    {
        $result = $this->service([
            'type' => 'trip_update',
            'headerTimestamp' => time() - 400,
            'trips' => [],
        ])->probe('https://1.1.1.1/feed.pb');

        self::assertSame('stale', $result['status']);
        self::assertSame(0, $result['tripCount']);
    }

    #[Test]
    public function itReportsAVehicleFeedAsTheWrongType(): void
    {
        $result = $this->service([
            'type' => 'vehicle',
            'headerTimestamp' => time() - 10,
        ])->probe('https://1.1.1.1/feed.pb');

        self::assertSame('wrong_type', $result['status']);
        self::assertSame('vehicle', $result['feedType']);
        self::assertSame(0, $result['tripCount']);
    }

    #[Test]
    public function itReportsAnUndecodableFeed(): void
    {
        $result = $this->service(
            ['type' => 'trip_update'],
            ['/bin/false'],
        )->probe('https://1.1.1.1/feed.pb');

        self::assertSame('invalid', $result['status']);
    }

    #[Test]
    public function itReportsAnHttpFailureAsUnreachable(): void
    {
        $result = $this->service(
            ['type' => 'trip_update'],
            null,
            new MockHttpClient([new MockResponse('', ['http_code' => 500])]),
        )->probe('https://1.1.1.1/feed.pb');

        self::assertSame('unreachable', $result['status']);
    }

    #[Test]
    public function itRejectsANonHttpsUrlWithoutFetching(): void
    {
        $result = $this->service(
            ['type' => 'trip_update'],
            null,
            new MockHttpClient(),
        )->probe('http://1.1.1.1/feed.pb');

        self::assertSame('invalid_url', $result['status']);
    }

    #[Test]
    public function itFollowsARedirectToTheFeed(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location' => 'https://1.1.1.1/feed-final.pb'],
            ]),
            new MockResponse('bytes', ['http_code' => 200]),
        ]);

        $result = $this->service(
            ['type' => 'trip_update', 'headerTimestamp' => time()],
            null,
            $client,
        )->probe('https://8.8.8.8/feed.pb');

        self::assertSame('ok', $result['status']);
    }

    #[Test]
    public function aRedirectToAPrivateIpYieldsNoRealtimeData(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location' => 'https://10.0.0.1/feed.pb'],
            ]),
        ]);
        $service = new TransitRealtimeService(
            new OutboundHttpFetcher($client, new OutboundUrlGuard(), new NullLogger()),
            new ArrayAdapter(),
            new NullLogger(),
            ['/bin/echo', '{"type":"trip_update"}'],
        );

        self::assertSame([], $service->tripUpdates('source-uuid', 'https://8.8.8.8/feed.pb'));
    }

    /**
     * @param array<string, mixed> $decoded
     * @param list<string>|null    $decoderCommand
     */
    private function service(
        array $decoded,
        ?array $decoderCommand = null,
        ?MockHttpClient $client = null,
    ): TransitRealtimeService {
        $client ??= new MockHttpClient([new MockResponse('bytes', ['http_code' => 200])]);

        return new TransitRealtimeService(
            new OutboundHttpFetcher($client, new OutboundUrlGuard(), new NullLogger()),
            $this->createStub(CacheInterface::class),
            new NullLogger(),
            $decoderCommand ?? ['/bin/echo', json_encode($decoded, JSON_THROW_ON_ERROR)],
        );
    }
}
