<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\Test;

final class TeamApiTest extends ApiTestCase
{
    #[Test]
    public function itLetsAPlayerChooseAndSwitchSides(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);
        self::assertIsString($game['roundUuid']);
        $gameUuid = $game['uuid'];
        $roundUuid = $game['roundUuid'];

        $join = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($join['playerUuid']);
        self::assertIsString($join['mercureToken']);
        $playerUuid = $join['playerUuid'];
        $token = $join['mercureToken'];

        $team = $client->request('POST', "/api/rounds/{$roundUuid}/team", $this->headersWithToken($token) + [
            'json' => ['side' => 'hider'],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('hider', $team['side']);
        self::assertSame($playerUuid, $team['playerUuid']);
        self::assertIsString($team['mercureToken']);
        self::assertContains("game/{$gameUuid}/round/{$roundUuid}/seeker-locations", (array) $team['topics']);
        self::assertContains("game/{$gameUuid}/round/{$roundUuid}/hider-locations", (array) $team['topics']);

        $switched = $client->request('POST', "/api/rounds/{$roundUuid}/team", $this->headersWithToken($team['mercureToken']) + [
            'json' => ['side' => 'seeker'],
        ])->toArray();

        self::assertSame('seeker', $switched['side']);
        self::assertSame($playerUuid, $switched['playerUuid']);
        self::assertContains("game/{$gameUuid}/round/{$roundUuid}/seeker-locations", (array) $switched['topics']);
        self::assertNotContains("game/{$gameUuid}/round/{$roundUuid}/hider-locations", (array) $switched['topics']);
    }

    #[Test]
    public function itRejectsAPlayerFromAnotherGame(): void
    {
        $client = static::createClient();

        $gameA = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($gameA['roundUuid']);
        $roundUuidA = $gameA['roundUuid'];

        $gameB = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Paris', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($gameB['uuid']);
        $gameUuidB = $gameB['uuid'];

        $joinB = $client->request('POST', "/api/games/{$gameUuidB}/join", self::AUTH + [
            'json' => ['name' => 'Bob', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($joinB['playerUuid']);
        self::assertIsString($joinB['mercureToken']);

        $client->request('POST', "/api/rounds/{$roundUuidA}/team", $this->headersWithToken($joinB['mercureToken']) + [
            'json' => ['side' => 'seeker'],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'team.player_wrong_game']);
    }

    #[Test]
    public function itRequiresASubscriberToken(): void
    {
        $client = static::createClient();
        $game = $this->createGame();

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/team", self::AUTH + [
            'json' => ['side' => 'seeker'],
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);
    }
}
