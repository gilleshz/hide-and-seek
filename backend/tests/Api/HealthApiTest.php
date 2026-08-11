<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;

final class HealthApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    #[Test]
    public function healthzReturnsOkWithoutApiKey(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/healthz');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(['status' => 'ok'], $response->toArray());
    }

    #[Test]
    public function healthzIsNotGuardedByTheApiKeyListener(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz', ['headers' => ['X-API-KEY' => '']]);

        self::assertResponseStatusCodeSame(200);
    }
}
