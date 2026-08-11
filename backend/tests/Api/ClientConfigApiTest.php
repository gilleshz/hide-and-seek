<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

final class ClientConfigApiTest extends ApiTestCase
{
    #[Test]
    public function clientConfigEndpointReturns200(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/client-config', self::AUTH);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
    }

    #[Test]
    public function clientConfigReturnsExpectedShape(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/api/client-config', self::AUTH);

        $data = $response->toArray();
        self::assertArrayHasKey('mapStyleAvailable', $data);
        self::assertArrayHasKey('availableStyles', $data);
        self::assertIsBool($data['mapStyleAvailable']);
        self::assertIsArray($data['availableStyles']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function stadiaApiKeyIsNullWhenNotConfigured(): void
    {
        foreach (['STADIA_API_KEY', 'THUNDERFOREST_API_KEY', 'MAPTILER_API_KEY'] as $var) {
            putenv($var);
            unset($_ENV[$var], $_SERVER[$var]);
        }

        $client = static::createClient();
        $response = $client->request('GET', '/api/client-config', self::AUTH);

        $data = $response->toArray();
        self::assertFalse($data['mapStyleAvailable']);
        self::assertEmpty($data['availableStyles']);
        self::assertArrayNotHasKey('stadiaApiKey', $data);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function thunderforestApiKeyIsNullWhenNotConfigured(): void
    {
        foreach (['STADIA_API_KEY', 'THUNDERFOREST_API_KEY', 'MAPTILER_API_KEY'] as $var) {
            putenv($var);
            unset($_ENV[$var], $_SERVER[$var]);
        }

        $client = static::createClient();
        $response = $client->request('GET', '/api/client-config', self::AUTH);

        $data = $response->toArray();
        self::assertArrayNotHasKey('thunderforestApiKey', $data);
    }

    #[Test]
    public function maptilerApiKeyIsNullWhenNotConfigured(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/api/client-config', self::AUTH);

        $data = $response->toArray();
        self::assertArrayNotHasKey('maptilerApiKey', $data);
    }
}
