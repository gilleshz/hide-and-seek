<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\ApiResource\GtfsSourceResource;
use App\Entity\GtfsSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GtfsSourceResourceTest extends TestCase
{
    #[Test]
    public function itExposesTheSourceMetadataAndRoutes(): void
    {
        $source = new GtfsSource('Feed', '/tmp/feed.zip', 'source-uuid');

        $resource = GtfsSourceResource::fromSource($source, [[
            'routeId' => 'R1',
            'shortName' => '1',
            'longName' => 'One',
            'routeType' => 0,
            'color' => 'ff0000',
            'textColor' => 'ffffff',
        ]], [
            'sizeBytes' => 123456,
            'uncompressedBytes' => 987654,
            'requiresSubset' => true,
        ]);

        self::assertSame('source-uuid', $resource->uuid);
        self::assertSame('Feed', $resource->name);
        self::assertTrue($resource->requiresSubset);
        self::assertSame(123456, $resource->sizeBytes);
        self::assertSame(987654, $resource->uncompressedBytes);
        self::assertCount(1, $resource->routes);
        self::assertSame('R1', $resource->routes[0]->routeId);
        self::assertSame('1', $resource->routes[0]->shortName);
        self::assertSame(0, $resource->routes[0]->routeType);
    }
}
