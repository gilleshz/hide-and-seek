<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use PHPUnit\Framework\Attributes\Test;

final class PlayerRemoveApiTest extends ApiTestCase
{
    #[Test]
    public function theHostCanRemoveAnotherPlayerAndTheirTokenDies(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $host = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'seeker');
        $bob = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Bob', 'hider');

        $removed = $client->request('POST', "/api/games/{$game['uuid']}/players/{$bob['playerUuid']}/remove", $this->headersWithToken($host['token']))->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame($bob['playerUuid'], $removed['playerUuid']);
        self::assertSame('Bob', $removed['displayName']);
        self::assertArrayNotHasKey('password', $removed);

        // The removed player's subscriber token is dead for every action.
        $client->request('POST', "/api/rounds/{$game['roundUuid']}/location", $this->headersWithToken($bob['token']) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);
        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.player_left']);

        // Option A semantics: the removed player can come back with their password, same identity.
        $returned = $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
            'json' => ['name' => 'Bob', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame($bob['playerUuid'], $returned['playerUuid']);
    }

    #[Test]
    public function aNonHostCannotRemove(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $host = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'seeker');
        $bob = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Bob', 'hider');

        $client->request('POST', "/api/games/{$game['uuid']}/players/{$host['playerUuid']}/remove", $this->headersWithToken($bob['token']));

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'player.remove_not_host']);
    }

    #[Test]
    public function removingAnUnknownOrForeignPlayerFails(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $other = $this->createGame();
        $host = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'seeker');
        $outsider = $this->joinAndPickSide($client, $other['uuid'], $other['roundUuid'], 'Eve', 'hider');

        $client->request('POST', "/api/games/{$game['uuid']}/players/00000000-0000-4000-8000-000000000000/remove", $this->headersWithToken($host['token']));
        self::assertResponseStatusCodeSame(404);
        self::assertJsonContains(['errorKey' => 'player.not_found']);

        $client->request('POST', "/api/games/{$game['uuid']}/players/{$outsider['playerUuid']}/remove", $this->headersWithToken($host['token']));
        self::assertResponseStatusCodeSame(404);
        self::assertJsonContains(['errorKey' => 'player.not_found']);
    }

    #[Test]
    public function removingAnAlreadyLeftPlayerFails(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $host = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'seeker');
        $bob = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Bob', 'hider');

        $client->request('POST', "/api/games/{$game['uuid']}/leave", $this->headersWithToken($bob['token']));
        self::assertResponseStatusCodeSame(204);

        $client->request('POST', "/api/games/{$game['uuid']}/players/{$bob['playerUuid']}/remove", $this->headersWithToken($host['token']));
        self::assertResponseStatusCodeSame(404);
        self::assertJsonContains(['errorKey' => 'player.not_found']);
    }

    #[Test]
    public function aRemovalRequiresAnIdentity(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $bob = $this->joinAs($client, $game['uuid'], 'Bob');

        $client->request('POST', "/api/games/{$game['uuid']}/players/{$bob['playerUuid']}/remove", self::AUTH);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);
    }

    #[Test]
    public function theHostCanRemoveThemselvesAndHostPassesOn(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $host = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'seeker');
        $bob = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Bob', 'hider');

        $removed = $client->request('POST', "/api/games/{$game['uuid']}/players/{$host['playerUuid']}/remove", $this->headersWithToken($host['token']))->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame($host['playerUuid'], $removed['playerUuid']);

        // Bob is now first in the roster order: a host-gated action succeeds with his token.
        $client->request('POST', "/api/games/{$game['uuid']}/delete", $this->headersWithToken($bob['token']));
        self::assertResponseIsSuccessful();
    }

    /**
     * Joins with the shared test password.
     *
     * @return array{playerUuid: string, mercureToken: string}
     */
    private function joinAs(Client $client, string $gameUuid, string $name): array
    {
        $join = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => $name, 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertIsString($join['playerUuid']);
        self::assertIsString($join['mercureToken']);

        /** @var array{playerUuid: string, mercureToken: string} $join */
        return $join;
    }
}
