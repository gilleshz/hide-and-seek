<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\ValidatorInterface;
use App\Dto\GtfsRealtimeProbeInput;
use App\Service\OutboundUrlGuard;
use App\Service\TransitRealtimeService;
use App\State\GtfsRealtimeProbeProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;

final class GtfsRealtimeProbeProcessorTest extends TestCase
{
    #[Test]
    public function itMapsATripUpdateProbe(): void
    {
        $resource = $this->processor([
            'type' => 'trip_update',
            'headerTimestamp' => time() - 20,
            'trips' => ['T1' => []],
        ])->process($this->input(), new Post(), []);

        self::assertSame('ok', $resource->status);
        self::assertSame('trip_update', $resource->feedType);
        self::assertSame(1, $resource->tripCount);
    }

    #[Test]
    public function itMapsAVehicleProbe(): void
    {
        $resource = $this->processor([
            'type' => 'vehicle',
            'headerTimestamp' => time() - 20,
        ])->process($this->input(), new Post(), []);

        self::assertSame('wrong_type', $resource->status);
        self::assertSame('vehicle', $resource->feedType);
        self::assertSame(0, $resource->tripCount);
    }

    private function input(): GtfsRealtimeProbeInput
    {
        $input = new GtfsRealtimeProbeInput();
        $input->url = 'https://1.1.1.1/feed.pb';

        return $input;
    }

    /** @param array<string, mixed> $decoded */
    private function processor(array $decoded): GtfsRealtimeProbeProcessor
    {
        $service = new TransitRealtimeService(
            new MockHttpClient([new MockResponse('bytes', ['http_code' => 200])]),
            $this->createStub(CacheInterface::class),
            new OutboundUrlGuard(),
            new NullLogger(),
            ['/bin/echo', json_encode($decoded, JSON_THROW_ON_ERROR)],
        );

        return new GtfsRealtimeProbeProcessor($this->validator(), $service);
    }

    private function validator(): ValidatorInterface
    {
        return $this->createStub(ValidatorInterface::class);
    }
}
