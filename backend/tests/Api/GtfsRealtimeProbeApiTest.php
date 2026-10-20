<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\Test;

final class GtfsRealtimeProbeApiTest extends ApiTestCase
{
    #[Test]
    public function itRequiresTheApiKey(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/gtfs-sources/realtime-probe', [
            'json' => ['url' => 'https://example.com/feed.pb'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function itRejectsABlankUrl(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/gtfs-sources/realtime-probe', self::AUTH + [
            'json' => ['url' => ''],
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
