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
            'json' => ['name' => 'Alice', 'deviceKey' => $this->deviceKeyFor('Alice')],
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
        self::assertNotContains("game/{$gameUuid}/round/{$roundUuid}/hider-locations", (array) $team['topics']);

        $switched = $client->request('POST', "/api/rounds/{$roundUuid}/team", $this->headersWithToken($team['mercureToken']) + [
            'json' => ['side' => 'seeker'],
        ])->toArray();

        self::assertSame('seeker', $switched['side']);
        self::assertSame($playerUuid, $switched['playerUuid']);
        self::assertContains("game/{$gameUuid}/round/{$roundUuid}/seeker-locations", (array) $switched['topics']);
        self::assertNotContains("game/{$gameUuid}/round/{$roundUuid}/hider-locations", (array) $switched['topics']);
    }

    #[Test]
    public function itGrantsSpectatorsEveryTopicAndNeverHiderLocationsToASeeker(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $roundUuid = $game['roundUuid'];

        $seekerJoin = $this->joinGame($game, 'Sam');
        $seeker = $this->pickSide($game, $seekerJoin['playerUuid'], 'seeker', $seekerJoin['mercureToken']);
        $spectatorJoin = $this->joinGame($game, 'Eve');
        $spectator = $this->pickSide($game, $spectatorJoin['playerUuid'], 'spectator', $spectatorJoin['mercureToken']);

        self::assertSame('seeker', $seeker['side']);
        $seekerTopics = $this->tokenTopics($seeker['mercureToken']);
        self::assertContains("game/{$game['uuid']}/round/{$roundUuid}/seeker-locations", $seekerTopics);
        self::assertContains("game/{$game['uuid']}/round/{$roundUuid}/seeker-candidates", $seekerTopics);
        self::assertContains("game/{$game['uuid']}/round/{$roundUuid}/seeker-zone", $seekerTopics);
        self::assertNotContains("game/{$game['uuid']}/round/{$roundUuid}/hider-locations", $seekerTopics);

        self::assertSame('spectator', $spectator['side']);
        $lobbyTopics = $this->tokenTopics($spectator['mercureToken']);
        self::assertNotContains("game/{$game['uuid']}/round/{$roundUuid}/hider-locations", $lobbyTopics);

        // The hider topic is granted only after the start, so a lobby token kept across a role
        // switch can never carry hider locations into the round.
        $client->request(
            'POST',
            "/api/rounds/{$roundUuid}/start",
            $this->headersWithToken($seeker['mercureToken']) + ['json' => []],
        );
        self::assertResponseIsSuccessful();

        $seekerAfter = $this->pickSide($game, $seekerJoin['playerUuid'], 'seeker', $seeker['mercureToken']);
        $spectatorAfter = $this->pickSide($game, $spectatorJoin['playerUuid'], 'spectator', $spectator['mercureToken']);

        $seekerTopics = $this->tokenTopics($seekerAfter['mercureToken']);
        self::assertNotContains("game/{$game['uuid']}/round/{$roundUuid}/hider-locations", $seekerTopics);

        $spectatorTopics = $this->tokenTopics($spectatorAfter['mercureToken']);
        self::assertContains("game/{$game['uuid']}/round/{$roundUuid}/seeker-locations", $spectatorTopics);
        self::assertContains("game/{$game['uuid']}/round/{$roundUuid}/hider-locations", $spectatorTopics);
        self::assertContains("game/{$game['uuid']}/round/{$roundUuid}/seeker-candidates", $spectatorTopics);
        self::assertContains("game/{$game['uuid']}/round/{$roundUuid}/seeker-zone", $spectatorTopics);
        self::assertContains("game/{$game['uuid']}/round/{$roundUuid}/possible-area", $spectatorTopics);
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
            'json' => ['name' => 'Bob', 'deviceKey' => $this->deviceKeyFor('Bob')],
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

    #[Test]
    public function aSeekerWhoLeavesAndRejoinsMidRoundStillCannotBecomeASpectator(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $seeker = $this->newPlayer($game, 'Alice', 'seeker');
        $client->request('POST', "/api/rounds/{$game['roundUuid']}/start", $this->headersWithToken($seeker['token']) + [
            'json' => [],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', "/api/games/{$game['uuid']}/leave", $this->headersWithToken($seeker['token']));
        self::assertResponseStatusCodeSame(204);

        $rejoined = $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'deviceKey' => $this->deviceKeyFor('Alice')],
        ])->toArray();
        self::assertSame($seeker['playerUuid'], $rejoined['playerUuid']);
        self::assertIsString($rejoined['mercureToken']);

        $client->request(
            'POST',
            "/api/rounds/{$game['roundUuid']}/team",
            $this->headersWithToken($rejoined['mercureToken']) + ['json' => ['side' => 'spectator']],
        );
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'team.change_only_in_lobby']);

        $reconfirmed = $client->request(
            'POST',
            "/api/rounds/{$game['roundUuid']}/team",
            $this->headersWithToken($rejoined['mercureToken']) + ['json' => ['side' => 'seeker']],
        )->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('seeker', $reconfirmed['side']);
    }

    #[Test]
    public function aSpectatorWhoLeavesAndRejoinsMidRoundStillCannotBecomeASeeker(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $spectator = $this->newPlayer($game, 'Eve', 'spectator');
        $client->request(
            'POST',
            "/api/rounds/{$game['roundUuid']}/start",
            $this->headersWithToken($spectator['token']) + ['json' => []],
        );
        self::assertResponseIsSuccessful();

        $client->request('POST', "/api/games/{$game['uuid']}/leave", $this->headersWithToken($spectator['token']));
        self::assertResponseStatusCodeSame(204);

        $rejoined = $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
            'json' => ['name' => 'Eve', 'deviceKey' => $this->deviceKeyFor('Eve')],
        ])->toArray();
        self::assertSame($spectator['playerUuid'], $rejoined['playerUuid']);
        self::assertIsString($rejoined['mercureToken']);

        $client->request(
            'POST',
            "/api/rounds/{$game['roundUuid']}/team",
            $this->headersWithToken($rejoined['mercureToken']) + ['json' => ['side' => 'seeker']],
        );
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'team.change_only_in_lobby']);

        $reconfirmed = $client->request(
            'POST',
            "/api/rounds/{$game['roundUuid']}/team",
            $this->headersWithToken($rejoined['mercureToken']) + ['json' => ['side' => 'spectator']],
        )->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('spectator', $reconfirmed['side']);
    }

    /**
     * @return list<string>
     */
    private function tokenTopics(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts);
        $payload = json_decode(base64_decode($parts[1], true) ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $details = $payload['authorization_details'] ?? null;
        self::assertIsArray($details);
        $grant = $details[0] ?? null;
        self::assertIsArray($grant);
        $topics = $grant['topics'] ?? null;
        self::assertIsArray($topics);

        $matches = [];
        foreach ($topics as $topic) {
            self::assertIsArray($topic);
            $match = $topic['match'] ?? null;
            self::assertIsString($match);
            $matches[] = $match;
        }

        return $matches;
    }
}
