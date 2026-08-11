<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\OverpassEmptyPolicy;
use App\Service\OverpassHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(OverpassHttpClient::class)]
final class OverpassHttpClientTest extends TestCase
{
    private const string EMPTY_WITH_REMARK =
        '{"version": 0.6, "elements": [], "remark": "runtime error: Query timed out in \"query\""}';
    private const string GOOD_RESPONSE =
        '{"version": 0.6, "elements": [{"type": "relation", "id": 1, "tags": {"route": "tram"}}]}';
    private const string LEGIT_EMPTY = '{"version": 0.6, "elements": []}';

    #[Test]
    public function itFailsOverWhenAMirrorReturnsEmptyWithRemark(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(self::EMPTY_WITH_REMARK),
            new MockResponse(self::GOOD_RESPONSE),
        ]);
        $client = new OverpassHttpClient($httpClient, 'http://mirror-a/api,http://mirror-b/api', false);

        $body = $client->fetch('data', 60, 1_000_000, OverpassEmptyPolicy::RejectWithRemark);

        self::assertSame(self::GOOD_RESPONSE, $body);
    }

    #[Test]
    public function itThrowsWhenAllMirrorsReturnEmptyWithRemark(): void
    {
        $responses = array_fill(0, 5, new MockResponse(self::EMPTY_WITH_REMARK));
        $httpClient = new MockHttpClient($responses);
        $client = new OverpassHttpClient($httpClient, 'http://mirror-a/api,http://mirror-b/api', false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch Overpass data');

        $client->fetch('data', 60, 1_000_000, OverpassEmptyPolicy::RejectWithRemark);
    }

    #[Test]
    public function itReturnsALegitimatelyEmptyResponseAsIs(): void
    {
        $httpClient = new MockHttpClient([new MockResponse(self::LEGIT_EMPTY)]);
        $client = new OverpassHttpClient($httpClient, 'http://mirror-a/api', false);

        $body = $client->fetch('data', 60, 1_000_000, OverpassEmptyPolicy::RejectWithRemark);

        self::assertSame(self::LEGIT_EMPTY, $body);
    }

    /** A mirror whose extract does not cover the queried area answers empty with no remark at all. */
    #[Test]
    public function itFailsOverWhenAMirrorReturnsEmptyWithoutARemarkAndEmptyIsRejected(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(self::LEGIT_EMPTY),
            new MockResponse(self::GOOD_RESPONSE),
        ]);
        $client = new OverpassHttpClient($httpClient, 'http://mirror-a/api,http://mirror-b/api', false);

        $body = $client->fetch('data', 60, 1_000_000, OverpassEmptyPolicy::RejectAny);

        self::assertSame(self::GOOD_RESPONSE, $body);
    }

    #[Test]
    public function itAcceptsEmptyWithRemarkWhenRejectionIsDisabled(): void
    {
        $httpClient = new MockHttpClient([new MockResponse(self::EMPTY_WITH_REMARK)]);
        $client = new OverpassHttpClient($httpClient, 'http://mirror-a/api', false);

        $body = $client->fetch('data', 60, 1_000_000);

        self::assertSame(self::EMPTY_WITH_REMARK, $body);
    }

    #[Test]
    public function itNeverFollowsRedirects(): void
    {
        $options = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $requestOptions) use (&$options): MockResponse {
            $options = $requestOptions;

            return new MockResponse(self::GOOD_RESPONSE);
        });
        $client = new OverpassHttpClient($httpClient, 'http://mirror-a/api', false);

        $client->fetch('data', 60, 1_000_000);

        self::assertSame(0, $options['max_redirects'] ?? null);
        self::assertSame(60.0, $options['timeout'] ?? null);
        self::assertSame('data=data', $options['body'] ?? null);
    }
}
