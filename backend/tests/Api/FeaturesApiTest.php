<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\Client;
use App\Entity\Feature;
use App\Enum\FeatureType;
use App\Repository\GameRepository;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
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
            'json' => ['name' => $name, 'deviceKey' => $this->deviceKeyFor($name)],
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
    public function hiderReturnsFeatures(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'FeatHider', 'hider');

        $games = self::getContainer()->get(GameRepository::class);
        self::assertInstanceOf(GameRepository::class, $games);
        $game = $games->findOneByUuid($ctx['gameUuid']);
        self::assertNotNull($game);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $feature = new Feature($game, FeatureType::Museum, 'Louvre', new Point(13.4, 52.52));
        $em->persist($feature);
        $em->flush();

        $client->request('GET', "/api/rounds/{$ctx['roundUuid']}/features", $this->headersWithToken($ctx['token']) + [
            'query' => ['type' => 'museum'],
        ]);

        self::assertResponseStatusCodeSame(200);
        $response = $client->getResponse();
        self::assertNotNull($response);
        /** @var array<array-key, mixed> $data */
        $data = $response->toArray();
        $members = $data['hydra:member'] ?? $data['member'] ?? $data;
        self::assertIsArray($members);
        self::assertCount(1, $members);
        $returned = $members[0];
        self::assertIsArray($returned);
        self::assertSame($feature->getUuid(), $returned['uuid'] ?? null);
        self::assertSame('Louvre', $returned['name'] ?? null);
        self::assertSame(52.52, $returned['lat'] ?? null);
        self::assertSame(13.4, $returned['lng'] ?? null);
    }

    #[Test]
    public function playerWithoutASideReturnsError(): void
    {
        $client = static::createClient();
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'FeatNoSide', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);
        $roundUuid = $game['roundUuid'];
        $gameUuid = $game['uuid'];

        $join = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'FeatNoSide', 'deviceKey' => $this->deviceKeyFor('FeatNoSide')],
        ])->toArray();
        self::assertIsString($join['mercureToken']);

        $client->request('GET', "/api/rounds/{$roundUuid}/features", $this->headersWithToken($join['mercureToken']) + [
            'query' => ['type' => 'museum'],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'feature_collection.not_member']);
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
