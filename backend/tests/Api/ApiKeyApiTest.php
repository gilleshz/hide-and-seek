<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use App\Tests\Support\IdentityFactory;
use PHPUnit\Framework\Attributes\Test;

final class ApiKeyApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const array AUTH = ['headers' => ['X-API-KEY' => 'test-key']];

    #[Test]
    public function headerApiKeyAuthenticates(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/client-config', self::AUTH);

        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function queryKeyFallbackIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/client-config?key=test-key');

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function wrongHeaderApiKeyIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/client-config', ['headers' => ['X-API-KEY' => 'wrong-key']]);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function encodedApiPrefixDoesNotBypassTheApiKey(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ap%69/client-config');
        self::assertResponseStatusCodeSame(401);

        $client->request('GET', '/ap%69/client-config', self::AUTH);
        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function joinWithoutApiKeyIsRejected(): void
    {
        $client = static::createClient();
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);

        $client->request('POST', "/api/games/{$game['uuid']}/join", [
            'json' => ['name' => 'no-key-user', 'deviceKey' => IdentityFactory::deviceKey('no-key-user')],
        ]);

        self::assertResponseStatusCodeSame(401);
    }
}
