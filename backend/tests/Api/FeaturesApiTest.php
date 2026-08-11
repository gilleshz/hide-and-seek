<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use PHPUnit\Framework\Attributes\Test;

final class FeaturesApiTest extends ApiTestCase
{
    /**
     * @return array{roundUuid: string, gameUuid: string, playerUuid: string, token: string}
     */
    private function createGameAndJoinAs(Client $client, string $name, string $side): array
    {
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => $name, 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);

        $join = $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
            'json' => ['name' => $name, 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($join['playerUuid']);
        self::assertIsString($join['mercureToken']);

        $team = $client->request('POST', "/api/rounds/{$game['roundUuid']}/team", $this->headersWithToken($join['mercureToken']) + [
            'json' => ['side' => $side],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertIsString($team['mercureToken']);

        return [
            'roundUuid' => $game['roundUuid'],
            'gameUuid' => $game['uuid'],
            'playerUuid' => $join['playerUuid'],
            'token' => $team['mercureToken'],
        ];
    }

    #[Test]
    public function missingTypeReturnsError(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'FeatTest', 'seeker');

        $client->request('GET', "/api/rounds/{$ctx['roundUuid']}/features", $this->headersWithToken($ctx['token']));

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function invalidTypeReturnsError(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'FeatBad', 'seeker');

        $client->request('GET', "/api/rounds/{$ctx['roundUuid']}/features", $this->headersWithToken($ctx['token']) + [
            'query' => ['type' => 'nonexistent_type'],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function nonSeekerReturnsError(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'FeatHider', 'hider');

        $client->request('GET', "/api/rounds/{$ctx['roundUuid']}/features", $this->headersWithToken($ctx['token']) + [
            'query' => ['type' => 'museum'],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function returnsFeaturesForValidType(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'FeatEmpty', 'seeker');

        $client->request('GET', "/api/rounds/{$ctx['roundUuid']}/features", $this->headersWithToken($ctx['token']) + [
            'query' => ['type' => 'museum'],
        ]);

        self::assertResponseStatusCodeSame(200);
        $response = $client->getResponse();
        self::assertNotNull($response);
        /** @var array<array-key, mixed> $data */
        $data = $response->toArray();
        $members = $data['hydra:member'] ?? $data['member'] ?? $data;
        if (is_array($members) && isset($members[0]) && is_array($members[0])) {
            $feature = $members[0];
            self::assertIsString($feature['uuid']);
            self::assertArrayHasKey('name', $feature);
            self::assertIsNumeric($feature['lat']);
            self::assertIsNumeric($feature['lng']);
        }
    }

    #[Test]
    public function queryingFeaturesWithoutATokenReturns401(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'FeatNoAuth', 'seeker');

        $client->request('GET', "/api/rounds/{$ctx['roundUuid']}/features", self::AUTH + [
            'query' => ['type' => 'museum'],
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);
    }

    #[Test]
    public function nonExistentRoundReturns404(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/rounds/00000000-0000-0000-0000-000000000000/features', self::AUTH + [
            'query' => ['type' => 'museum'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
