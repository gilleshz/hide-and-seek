<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Enum\RoundStatus;
use App\Repository\GameRepository;
use App\Repository\RoundRepository;
use App\Tests\Fake\FakeMercureHub;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;

final class IdentityGuardApiTest extends ApiTestCase
{
    private const string TEST_SECRET = 'test-mercure-secret-at-least-32-bytes-long!!';

    #[Test]
    public function aSeekerCannotRevealAsTheHider(): void
    {
        $client = static::createClient();
        [$gameUuid, $roundUuid, $seeker, $hider] = $this->gameWithSeekerAndHider($client);

        $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($hider['token']) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);

        $asked = $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seeker['token']) + [
            'json' => ['category' => 'radar', 'radiusMeters' => 500.0, 'seekerLat' => 52.52, 'seekerLng' => 13.405],
        ])->toArray();
        self::assertIsString($asked['uuid']);
        $questionUuid = $asked['uuid'];

        // The legacy body field is ignored: identity comes from the token, so the seeker reveals as themselves.
        $client->request('POST', "/api/questions/{$questionUuid}/reveal", $this->headersWithToken($seeker['token']) + [
            'json' => ['revealingPlayerUuid' => $hider['playerUuid']],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'question.hider_only']);

        $refetched = $client->request('GET', "/api/questions/{$questionUuid}", self::AUTH)->toArray();
        self::assertNull($refetched['revealedAt']);
        self::assertNull($refetched['radarAnswer']);

        $revealed = $client->request('POST', "/api/questions/{$questionUuid}/reveal", $this->headersWithToken($hider['token']))->toArray();
        self::assertResponseIsSuccessful();
        self::assertTrue($revealed['radarAnswer']);
    }

    #[Test]
    public function aPingBodyPlayerUuidCannotSpoofThePinger(): void
    {
        $client = static::createClient();
        [$gameUuid, $roundUuid, $alice] = $this->gameWithJoinedPlayer($client, 'Alice');

        $location = $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($alice['token']) + [
            'json' => ['playerUuid' => '00000000-0000-4000-8000-000000000000', 'lat' => 52.52, 'lng' => 13.405],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame($alice['playerUuid'], $location['playerUuid']);

        /** @var FakeMercureHub $hub */
        $hub = self::getContainer()->get(FakeMercureHub::class);
        $published = $hub->published();
        self::assertCount(1, $published);
        self::assertStringContainsString($alice['playerUuid'], $published[0]->getData());
    }

    #[Test]
    public function theEndgameProbeEndpointIsGone(): void
    {
        $client = static::createClient();
        $game = $this->createGame();

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/check-endgame", self::AUTH);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aPlayerOfAnotherGameCannotStopThisRound(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $otherGame = $this->createGame();
        [, $outsiderToken] = $this->joinAndPickSide($client, $otherGame['uuid'], $otherGame['roundUuid'], 'Eve', 'seeker');

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/stop", $this->headersWithToken($outsiderToken) + ['json' => []]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'round.player_wrong_game']);
    }

    #[Test]
    public function stoppingARoundWithoutATokenReturns401(): void
    {
        $client = static::createClient();
        $game = $this->createGame();

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/stop", self::AUTH + ['json' => []]);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);
    }

    #[Test]
    public function aPlayerOfTheGameCanStopTheRound(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $token = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'seeker')['token'];

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/start", $this->headersWithToken($token) + ['json' => []]);
        self::assertResponseIsSuccessful();
        $client->request('POST', "/api/rounds/{$game['roundUuid']}/stop", $this->headersWithToken($token) + ['json' => []]);

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function onlyAHiderCanDeclareAScoredStop(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $seeker = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Sam', 'seeker');
        $roundUuid = $game['roundUuid'];

        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($seeker['token']) + ['json' => []]);
        self::assertResponseIsSuccessful();

        $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($seeker['token']) + [
            'json' => ['caught' => true, 'hidingSeconds' => 3600],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'round.not_hider']);

        // The unscored abort stays open to any member of the game.
        $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($seeker['token']) + ['json' => []]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aChatBodyPlayerUuidCannotImpersonateAnotherPlayer(): void
    {
        $client = static::createClient();
        [$gameUuid, , $alice] = $this->gameWithJoinedPlayer($client, 'Alice');

        $message = $client->request('POST', "/api/games/{$gameUuid}/chat", $this->headersWithToken($alice['token']) + [
            'json' => ['playerUuid' => '00000000-0000-4000-8000-000000000000', 'body' => 'Hello'],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame($alice['playerUuid'], $message['senderUuid']);
    }

    #[Test]
    public function onlyTheHostCanDeleteTheGame(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $alice = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'seeker');
        $bob = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Bob', 'seeker');

        $client->request('POST', "/api/games/{$game['uuid']}/delete", $this->headersWithToken($bob['token']));
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'game.only_host_can_delete']);

        $client->request('POST', "/api/games/{$game['uuid']}/delete", $this->headersWithToken($alice['token']));
        self::assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function everyPlayerActionRequiresASubscriberToken(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $other = $this->createGame();
        $alice = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'hider');
        [, $seekerToken] = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Bob', 'seeker');
        $this->seek($client, $game['roundUuid']);

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/location", $this->headersWithToken($seekerToken) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);
        $asked = $client->request('POST', "/api/rounds/{$game['roundUuid']}/questions", $this->headersWithToken($seekerToken) + [
            'json' => ['category' => 'radar', 'radiusMeters' => 500.0, 'seekerLat' => 52.52, 'seekerLng' => 13.405],
        ])->toArray();
        self::assertIsString($asked['uuid']);

        $actions = [
            'team' => ['POST', "/api/rounds/{$game['roundUuid']}/team", ['json' => ['side' => 'seeker']]],
            'location' => ['POST', "/api/rounds/{$game['roundUuid']}/location", ['json' => ['lat' => 52.52, 'lng' => 13.405]]],
            'zone' => ['POST', "/api/rounds/{$game['roundUuid']}/zone", ['json' => ['lat' => 52.52, 'lng' => 13.405]]],
            'chat' => ['POST', "/api/games/{$game['uuid']}/chat", ['json' => ['body' => 'hi']]],
            'reveal' => ['POST', "/api/questions/{$asked['uuid']}/reveal", []],
            'stop' => ['POST', "/api/rounds/{$game['roundUuid']}/stop", ['json' => []]],
            'delete' => ['POST', "/api/games/{$game['uuid']}/delete", []],
            'leave' => ['POST', "/api/games/{$game['uuid']}/leave", []],
            'remove' => ['POST', "/api/games/{$game['uuid']}/players/{$alice['playerUuid']}/remove", []],
            'markers' => ['GET', "/api/rounds/{$game['roundUuid']}/seeker-candidate-markers", []],
            'possible-area' => ['GET', "/api/rounds/{$game['roundUuid']}/possible-area", []],
            'subscriber-token' => ['POST', "/api/rounds/{$game['roundUuid']}/subscriber-token", []],
            'rounds' => ['POST', "/api/games/{$other['uuid']}/rounds", []],
        ];

        foreach ($actions as $name => [$method, $url, $options]) {
            $client->request($method, $url, self::AUTH + $options);
            self::assertResponseStatusCodeSame(401, "{$name} must 401 without a subscriber token");
            self::assertJsonContains(['errorKey' => 'identity.token_missing']);
        }
    }

    #[Test]
    public function anExpiredTokenReturns401(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $player = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'seeker');

        $expired = (new LcobucciFactory(self::TEST_SECRET))->create(
            ["player/{$player['playerUuid']}/endgame"],
            [],
            ['exp' => new \DateTimeImmutable('-1 hour'), 'sub' => $player['playerUuid']],
        );

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/team", $this->headersWithToken($expired) + [
            'json' => ['side' => 'seeker'],
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_invalid']);
    }

    #[Test]
    public function aGarbledTokenReturns401(): void
    {
        $client = static::createClient();
        $game = $this->createGame();

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/team", $this->headersWithToken('not-a-jwt') + [
            'json' => ['side' => 'seeker'],
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_invalid']);
    }

    #[Test]
    public function teamPickingMintsATokenForTheCallerOnly(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $alice = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'seeker');
        $bob = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Bob', 'hider');

        // Legacy body field is ignored: the minted token names the caller, never the body's UUID.
        $team = $client->request('POST', "/api/rounds/{$game['roundUuid']}/team", $this->headersWithToken($alice['token']) + [
            'json' => ['playerUuid' => $bob['playerUuid'], 'side' => 'hider'],
        ])->toArray();

        self::assertSame($alice['playerUuid'], $team['playerUuid']);
        self::assertIsString($team['mercureToken']);

        $parts = explode('.', $team['mercureToken']);
        self::assertCount(3, $parts);
        $payload = json_decode(base64_decode($parts[1], true) ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame($alice['playerUuid'], $payload['sub'] ?? null);
    }

    #[Test]
    public function rejoiningAnExistingNameRequiresThePassword(): void
    {
        $client = static::createClient();
        [$gameUuid, , $alice] = $this->gameWithJoinedPlayer($client, 'Alice');

        $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice'],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'join.password_required']);
        self::assertJsonContains(['detail' => "This name is already used by another player. If it's yours, enter its password. Otherwise, pick a different name."]);

        $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => 'wrong-password'],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'join.password_invalid']);
        self::assertJsonContains(['detail' => "Wrong password for this name. If it's not your name, pick a different one."]);

        // The DTO backstop: an empty or out-of-range password never reaches the service.
        foreach (['', 'abc', str_repeat('x', 65)] as $bad) {
            $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
                'json' => ['name' => 'Alice', 'password' => $bad],
            ]);
            self::assertResponseStatusCodeSame(422, "Password '{$bad}' must 422.");
        }

        $rejoin = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame($alice['playerUuid'], $rejoin['playerUuid']);
        self::assertArrayNotHasKey('joinSecret', $rejoin);
    }

    #[Test]
    public function aWhitespaceOnlyNameIsRejected(): void
    {
        $client = static::createClient();
        $game = $this->createGame();

        foreach (['   ', "\t", " \t "] as $blankName) {
            $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
                'json' => ['name' => $blankName, 'password' => self::JOIN_PASSWORD],
            ]);
            self::assertResponseStatusCodeSame(422, "Whitespace-only name '{$blankName}' must 422.");
        }
    }

    #[Test]
    public function theJoinResponseNeverContainsACredential(): void
    {
        $client = static::createClient();
        $game = $this->createGame();

        $first = $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
            'json' => ['name' => 'Dora', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertArrayNotHasKey('joinSecret', $first);
        self::assertArrayNotHasKey('password', $first);
        self::assertArrayNotHasKey('passwordHash', $first);

        $second = $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
            'json' => ['name' => 'Dora', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertSame($first['playerUuid'], $second['playerUuid']);
        self::assertArrayNotHasKey('joinSecret', $second);
    }

    #[Test]
    public function aLeftPlayersTokenDiesForEveryActionUntilTheyRejoin(): void
    {
        $client = static::createClient();
        [$gameUuid, $roundUuid, $alice] = $this->gameWithJoinedPlayer($client, 'Alice');

        $client->request('POST', "/api/games/{$gameUuid}/leave", $this->headersWithToken($alice['token']));
        self::assertResponseStatusCodeSame(204);

        $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($alice['token']) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);
        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.player_left']);

        $returned = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame($alice['playerUuid'], $returned['playerUuid']);
        self::assertIsString($returned['mercureToken']);

        // Leaving dropped the memberships: the rejoined player picks a side again, then pings.
        $team = $client->request('POST', "/api/rounds/{$roundUuid}/team", $this->headersWithToken($returned['mercureToken']) + [
            'json' => ['side' => 'hider'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertIsString($team['mercureToken']);
        $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($team['mercureToken']) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aRoundNTokencannotReachTheNextRoundsHiderLocations(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $alice = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'hider');

        $roundN = $game['roundUuid'];
        $topicsN = $this->refreshTopics($client, $alice['token'], $roundN);
        self::assertContains("game/{$game['uuid']}/round/{$roundN}/hider-locations", $topicsN);

        $client->request('POST', "/api/rounds/{$roundN}/start", $this->headersWithToken($alice['token']) + ['json' => []]);
        $client->request('POST', "/api/rounds/{$roundN}/stop", $this->headersWithToken($alice['token']) + ['json' => []]);

        $next = $client->request('POST', "/api/games/{$game['uuid']}/rounds", $this->headersWithToken($alice['token']))->toArray();
        self::assertIsString($next['roundUuid']);
        $roundN1 = $next['roundUuid'];

        // The old token keeps only round-N topics; the refresh mints round N+1 topics for the swapped side.
        self::assertNotContains("game/{$game['uuid']}/round/{$roundN1}/hider-locations", $this->refreshTopics($client, $alice['token'], $roundN));

        $refreshed = $client->request('POST', "/api/rounds/{$roundN1}/subscriber-token", $this->headersWithToken($alice['token']))->toArray();
        self::assertResponseIsSuccessful();
        self::assertContains("game/{$game['uuid']}/round/{$roundN1}/seeker-locations", (array) $refreshed['topics']);
        self::assertNotContains("game/{$game['uuid']}/round/{$roundN1}/hider-locations", (array) $refreshed['topics']);
    }

    #[Test]
    public function joinsAreRateLimitedPerIp(): void
    {
        $client = static::createClient();
        $game = $this->createGame();

        for ($i = 0; $i < 30; ++$i) {
            $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
                'json' => ['name' => "Join{$i}", 'password' => self::JOIN_PASSWORD],
            ]);
            self::assertResponseIsSuccessful();
        }

        $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
            'json' => ['name' => 'Join31', 'password' => self::JOIN_PASSWORD],
        ]);

        self::assertResponseStatusCodeSame(429);
        self::assertJsonContains(['errorKey' => 'rate_limit.exceeded']);
    }

    #[Test]
    public function gameCreationsAreRateLimitedPerIp(): void
    {
        $client = static::createClient();

        for ($i = 0; $i < 3; ++$i) {
            $client->request('POST', '/api/games', self::AUTH + [
                'json' => ['name' => "Create{$i}", 'size' => 'M', 'edition' => 'metric'],
            ]);
            self::assertResponseIsSuccessful();
        }

        $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Create4', 'size' => 'M', 'edition' => 'metric'],
        ]);

        self::assertResponseStatusCodeSame(429);
        self::assertJsonContains(['errorKey' => 'rate_limit.exceeded']);
    }

    #[Test]
    public function locationIngestsAreRateLimitedPerPlayer(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $alice = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'hider');

        for ($i = 0; $i < 40; ++$i) {
            $client->request('POST', "/api/rounds/{$game['roundUuid']}/location", $this->headersWithToken($alice['token']) + [
                'json' => ['lat' => 52.52, 'lng' => 13.405],
            ]);
            self::assertResponseIsSuccessful();
        }

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/location", $this->headersWithToken($alice['token']) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);

        self::assertResponseStatusCodeSame(429);
        self::assertJsonContains(['errorKey' => 'rate_limit.exceeded']);
    }

    #[Test]
    public function candidateMarkersAreSeekerOnlyForReadsDeletesAndLists(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $hider = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Hank', 'hider');
        $seeker = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Sam', 'seeker');
        $roundUuid = $game['roundUuid'];

        $client->request('GET', "/api/rounds/{$roundUuid}/seeker-candidate-markers", $this->headersWithToken($hider['token']));
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'seeker_candidate.not_seeker']);

        $marker = $client->request('POST', "/api/rounds/{$roundUuid}/seeker-candidate-markers", $this->headersWithToken($seeker['token']) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ])->toArray();
        self::assertIsString($marker['uuid']);

        $client->request('DELETE', "/api/rounds/{$roundUuid}/seeker-candidate-markers/{$marker['uuid']}", $this->headersWithToken($hider['token']));
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'seeker_candidate.not_seeker']);

        $listed = $client->request('GET', "/api/rounds/{$roundUuid}/seeker-candidate-markers", $this->headersWithToken($seeker['token']))->toArray();
        self::assertIsArray($listed['member']);
        self::assertCount(1, $listed['member']);
    }

    #[Test]
    public function thePossibleAreaIsGatedOnTheSharedWithHidersFlag(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $hider = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Hank', 'hider');
        $seeker = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Sam', 'seeker');
        $roundUuid = $game['roundUuid'];

        $client->request('GET', "/api/rounds/{$roundUuid}/possible-area", self::AUTH);
        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);

        $client->request('GET', "/api/rounds/{$roundUuid}/possible-area", $this->headersWithToken($seeker['token']));
        self::assertResponseIsSuccessful();

        // Default flag is true (SETUP-8): the hider map keeps polling the overlay.
        $client->request('GET', "/api/rounds/{$roundUuid}/possible-area", $this->headersWithToken($hider['token']));
        self::assertResponseIsSuccessful();

        $games = self::getContainer()->get(GameRepository::class);
        self::assertInstanceOf(GameRepository::class, $games);
        $stored = $games->findOneByUuid($game['uuid']);
        self::assertNotNull($stored);
        $stored->setPossibleAreaSharedWithHiders(false);
        $games->save($stored);

        $client->request('GET', "/api/rounds/{$roundUuid}/possible-area", $this->headersWithToken($hider['token']));
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'possible_area.seekers_only']);
    }

    #[Test]
    public function theLocationAckReportsOnlyTheIngestThatStartedTheEndgame(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $hider = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Hank', 'hider');
        $seeker = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Sam', 'seeker');
        $roundUuid = $game['roundUuid'];

        $this->seedZone($roundUuid);
        $round = $this->storedRound($roundUuid);
        $round->setStatus(RoundStatus::Seeking)->setHidingPeriodEndsAt(new \DateTimeImmutable('-30 minutes'));
        $this->roundRepository()->save($round);

        $inside = $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($seeker['token']) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ])->toArray();
        self::assertTrue($inside['endgame']);

        $again = $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($seeker['token']) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ])->toArray();
        self::assertFalse($again['endgame']);

        $hiderPing = $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($hider['token']) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ])->toArray();
        self::assertFalse($hiderPing['endgame']);
    }

    #[Test]
    public function theRefreshEndpointScopesTopicsToTheCallersMembership(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $outsider = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Eve', 'seeker');
        $roundUuid = $game['roundUuid'];

        // A round of another game is refused outright.
        $foreign = $this->createGame();
        $client->request('POST', "/api/rounds/{$foreign['roundUuid']}/subscriber-token", $this->headersWithToken($outsider['token']));
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'round.player_wrong_game']);

        $scoped = $client->request('POST', "/api/rounds/{$roundUuid}/subscriber-token", $this->headersWithToken($outsider['token']))->toArray();
        self::assertResponseIsSuccessful();
        self::assertContains("game/{$game['uuid']}/round/{$roundUuid}/seeker-locations", (array) $scoped['topics']);
        self::assertContains("game/{$game['uuid']}/round/{$roundUuid}/seeker-candidates", (array) $scoped['topics']);
        self::assertNotContains("game/{$game['uuid']}/round/{$roundUuid}/hider-locations", (array) $scoped['topics']);

        // A round of Eve's own game where she has no membership: baseline only, no location topics.
        // The new round auto-seeds swapped memberships, so Eve's row is removed first to build the state.
        $bareRoundUuid = $this->nextRoundOf($game['uuid']);
        $this->dropMembershipOf($outsider['playerUuid'], $bareRoundUuid);
        $bare = $client->request('POST', "/api/rounds/{$bareRoundUuid}/subscriber-token", $this->headersWithToken($outsider['token']))->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotContains("game/{$game['uuid']}/round/{$bareRoundUuid}/seeker-locations", (array) $bare['topics']);
        self::assertNotContains("game/{$game['uuid']}/round/{$bareRoundUuid}/hider-locations", (array) $bare['topics']);
        self::assertContains("game/{$game['uuid']}/round/{$bareRoundUuid}/possible-area", (array) $bare['topics']);
    }

    #[Test]
    public function leavingAndRejoiningWithThePasswordReinstatesThePlayer(): void
    {
        $client = static::createClient();
        [$gameUuid, , $alice] = $this->gameWithJoinedPlayer($client, 'Alice');

        $client->request('POST', "/api/games/{$gameUuid}/leave", $this->headersWithToken($alice['token']));
        self::assertResponseStatusCodeSame(204);

        $returned = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame($alice['playerUuid'], $returned['playerUuid']);
        self::assertArrayNotHasKey('joinSecret', $returned);
    }

    /**
     * @return array{string, string, array{playerUuid: string, token: string}, array{playerUuid: string, token: string}}
     */
    private function gameWithSeekerAndHider(Client $client): array
    {
        $game = $this->createGame();
        $seeker = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Bob', 'seeker');
        $hider = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'hider');
        $round = $this->storedRound($game['roundUuid']);
        $round->setStatus(RoundStatus::Seeking)->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 minute'));
        $this->roundRepository()->save($round);

        return [$game['uuid'], $game['roundUuid'], $seeker, $hider];
    }

    /**
     * @return array{string, string, array{playerUuid: string, token: string}}
     */
    private function gameWithJoinedPlayer(Client $client, string $name): array
    {
        $game = $this->createGame();
        $session = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], $name, 'hider');

        return [$game['uuid'], $game['roundUuid'], $session];
    }

    private function storedRound(string $roundUuid): \App\Entity\Round
    {
        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);

        return $round;
    }

    private function seedZone(string $roundUuid): void
    {
        $round = $this->storedRound($roundUuid);
        /** @var \App\Repository\HidingZoneRepository $zones */
        $zones = self::getContainer()->get(\App\Repository\HidingZoneRepository::class);
        $zone = new \App\Entity\HidingZone($round, new \LongitudeOne\Spatial\PHP\Types\Geography\Point(13.405, 52.52), 500.0);
        $zones->save($zone);
    }

    private function seek(Client $client, string $roundUuid): void
    {
        $round = $this->storedRound($roundUuid);
        $round->setStatus(RoundStatus::Seeking)->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 minute'));
        $this->roundRepository()->save($round);
    }

    private function roundRepository(): RoundRepository
    {
        $rounds = self::getContainer()->get(RoundRepository::class);
        self::assertInstanceOf(RoundRepository::class, $rounds);

        return $rounds;
    }

    private function dropMembershipOf(string $playerUuid, string $roundUuid): void
    {
        /** @var \App\Repository\PlayerRepository $players */
        $players = self::getContainer()->get(\App\Repository\PlayerRepository::class);
        $player = $players->findOneByUuid($playerUuid);
        self::assertNotNull($player);
        /** @var \App\Repository\RoundMembershipRepository $memberships */
        $memberships = self::getContainer()->get(\App\Repository\RoundMembershipRepository::class);
        $memberships->removeByPlayer($player);
    }

    private function nextRoundOf(string $gameUuid): string
    {
        /** @var \App\Service\RoundService $roundService */
        $roundService = self::getContainer()->get(\App\Service\RoundService::class);
        /** @var \App\Repository\GameRepository $games */
        $games = self::getContainer()->get(\App\Repository\GameRepository::class);
        $game = $games->findOneByUuid($gameUuid);
        self::assertNotNull($game);
        /** @var \App\Repository\RoundRepository $rounds */
        $rounds = self::getContainer()->get(\App\Repository\RoundRepository::class);
        $current = $rounds->findActiveByGame($game);
        self::assertNotNull($current);
        $current->setStatus(\App\Enum\RoundStatus::Ended)->setSeekingEndedAt(new \DateTimeImmutable());
        $rounds->save($current);

        return $roundService->createNextRound($gameUuid)->getUuid();
    }

    /**
     * @return list<string>
     */
    private function refreshTopics(Client $client, string $token, string $roundUuid): array
    {
        $refreshed = $client->request('POST', "/api/rounds/{$roundUuid}/subscriber-token", $this->headersWithToken($token))->toArray();
        self::assertResponseIsSuccessful();
        $topics = $refreshed['topics'];
        self::assertIsArray($topics);

        /** @var list<string> $topics */
        return $topics;
    }
}
