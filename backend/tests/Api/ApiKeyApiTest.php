<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
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
    public function accountCreationWithoutApiKeyIsRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/accounts', ['json' => ['name' => 'no-key-user', 'password' => 'secret-pass']]);

        self::assertResponseStatusCodeSame(401);
    }
}
