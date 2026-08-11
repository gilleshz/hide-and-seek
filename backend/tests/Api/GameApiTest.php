<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Game;
use App\Entity\GameTransitLine;
use App\Repository\RoundRepository;
use App\Service\RoundService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;

final class GameApiTest extends ApiTestCase
{
    #[Test]
    public function itRejectsRequestsWithoutTheApiKey(): void
    {
        static::createClient()->request('POST', '/api/games', [
            'json' => ['name' => 'Berlin'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function itCreatesAGameJoinsItAndListsTheRoster(): void
    {
        $client = static::createClient();

        $created = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'L', 'edition' => 'metric'],
        ])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Berlin', $created['name']);
        self::assertSame('L', $created['size']);
        self::assertSame(180, $created['defaultHidingPeriodMinutes']);
        self::assertIsString($created['uuid']);
        self::assertIsString($created['roundUuid']);
        $uuid = $created['uuid'];

        $join = $client->request('POST', "/api/games/{$uuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($join['playerUuid']);
        self::assertIsString($join['mercureToken']);

        self::assertResponseIsSuccessful();
        self::assertSame('Alice', $join['displayName']);
        self::assertSame($uuid, $join['gameUuid']);
        self::assertSame($created['roundUuid'], $join['roundUuid']);
        self::assertNotEmpty($join['mercureToken']);
        self::assertContains("game/{$uuid}/roster", (array) $join['topics']);
        self::assertArrayNotHasKey('joinSecret', $join);

        $rejoin = $client->request('POST', "/api/games/{$uuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();

        self::assertSame($join['playerUuid'], $rejoin['playerUuid']);
        self::assertArrayNotHasKey('joinSecret', $rejoin);

        $roster = $client->request('GET', "/api/games/{$uuid}/players", self::AUTH)->toArray();

        self::assertResponseIsSuccessful();
        $members = $roster['member'];
        self::assertIsArray($members);
        self::assertCount(1, $members);
        $alice = $members[0];
        self::assertIsArray($alice);
        self::assertSame('Alice', $alice['displayName']);
        self::assertNull($alice['side']);

        $client->request('POST', "/api/rounds/{$join['roundUuid']}/team", $this->headersWithToken($join['mercureToken']) + [
            'json' => ['side' => 'hider'],
        ]);
        self::assertResponseIsSuccessful();

        $rosterAfterPick = $client->request('GET', "/api/games/{$uuid}/players", self::AUTH)->toArray();
        $membersAfterPick = $rosterAfterPick['member'];
        self::assertIsArray($membersAfterPick);
        $aliceAfterPick = $membersAfterPick[0];
        self::assertIsArray($aliceAfterPick);
        self::assertSame('hider', $aliceAfterPick['side']);
    }

    #[Test]
    public function itReportsTheLatestRoundsSidesInTheRosterAfterASecondRound(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'S', 'edition' => 'metric'],
        ])->toArray();
        $gameUuid = $game['uuid'];
        $firstRoundUuid = $game['roundUuid'];
        self::assertIsString($gameUuid);
        self::assertIsString($firstRoundUuid);
        self::assertSame(30, $game['defaultHidingPeriodMinutes']);

        $alice = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($alice['mercureToken']);
        $bob = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Bob', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($bob['mercureToken']);

        $client->request('POST', "/api/rounds/{$firstRoundUuid}/team", $this->headersWithToken($alice['mercureToken']) + [
            'json' => ['side' => 'hider'],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', "/api/rounds/{$firstRoundUuid}/team", $this->headersWithToken($bob['mercureToken']) + [
            'json' => ['side' => 'seeker'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', "/api/rounds/{$firstRoundUuid}/start", $this->headersWithToken($alice['mercureToken']) + ['json' => []]);
        self::assertResponseIsSuccessful();

        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $firstRound = $rounds->findOneByUuid($firstRoundUuid);
        self::assertNotNull($firstRound);
        $firstRound->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 minute'));
        $rounds->save($firstRound);

        $client->request('POST', "/api/rounds/{$firstRoundUuid}/stop", $this->headersWithToken($alice['mercureToken']) + ['json' => []]);
        self::assertResponseIsSuccessful();

        // POST /games/{uuid}/rounds has a pre-existing IRI-generation 500 (response only); create via the service.
        /** @var RoundService $roundService */
        $roundService = self::getContainer()->get(RoundService::class);
        $second = $roundService->createNextRound($gameUuid);
        $secondRoundUuid = $second->getUuid();
        self::assertNotSame($firstRoundUuid, $secondRoundUuid);

        self::assertSame(
            ['Alice' => 'seeker', 'Bob' => 'hider'],
            $this->sidesByName($client, $gameUuid),
        );

        $client->request('POST', "/api/rounds/{$secondRoundUuid}/start", $this->headersWithToken($alice['mercureToken']) + ['json' => []]);
        self::assertResponseIsSuccessful();
        $secondRound = $rounds->findOneByUuid($secondRoundUuid);
        self::assertNotNull($secondRound);
        $secondRound->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 minute'));
        $rounds->save($secondRound);
        $client->request('POST', "/api/rounds/{$secondRoundUuid}/stop", $this->headersWithToken($alice['mercureToken']) + ['json' => []]);
        self::assertResponseIsSuccessful();

        // Every round Ended: the roster must fall back to the latest round's sides, not go blank.
        self::assertSame(
            ['Alice' => 'seeker', 'Bob' => 'hider'],
            $this->sidesByName($client, $gameUuid),
        );
    }

    #[Test]
    public function itShowsSwappedSidesInTheRosterAfterStartingTheNextRound(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'S', 'edition' => 'metric'],
        ])->toArray();
        $gameUuid = $game['uuid'];
        $roundUuid = $game['roundUuid'];
        self::assertIsString($gameUuid);
        self::assertIsString($roundUuid);

        $alice = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($alice['mercureToken']);

        $client->request('POST', "/api/rounds/{$roundUuid}/team", $this->headersWithToken($alice['mercureToken']) + [
            'json' => ['side' => 'hider'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Alice' => 'hider'], $this->sidesByName($client, $gameUuid));

        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($alice['mercureToken']) + ['json' => []]);
        $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($alice['mercureToken']) + ['json' => []]);
        self::assertResponseIsSuccessful();

        $client->request('POST', "/api/games/{$gameUuid}/rounds", $this->headersWithToken($alice['mercureToken']));
        self::assertResponseStatusCodeSame(201);

        self::assertSame(['Alice' => 'seeker'], $this->sidesByName($client, $gameUuid));
    }

    /**
     * @return array<string, ?string>
     */
    private function sidesByName(Client $client, string $gameUuid): array
    {
        $roster = $client->request('GET', "/api/games/{$gameUuid}/players", self::AUTH)->toArray();
        self::assertResponseIsSuccessful();
        $members = $roster['member'];
        self::assertIsArray($members);

        $sides = [];
        foreach ($members as $member) {
            self::assertIsArray($member);
            $name = $member['displayName'];
            $side = $member['side'];
            self::assertIsString($name);
            $sides[$name] = is_string($side) ? $side : null;
        }

        return $sides;
    }

    #[Test]
    public function itReturns404JoiningAnUnknownGame(): void
    {
        static::createClient()->request('POST', '/api/games/00000000-0000-0000-0000-000000000000/join', self::AUTH + [
            'json' => ['name' => 'Ghost'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function itUpdatesTheGameConfigInTheLobby(): void
    {
        $client = static::createClient();

        $created = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Nancy', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        $uuid = $created['uuid'];
        self::assertIsString($uuid);
        self::assertIsString($created['roundUuid']);

        $alice = $this->joinAndPickSide($client, $uuid, $created['roundUuid'], 'Alice', 'hider');

        $options = $this->headersWithToken($alice['token']);
        $options['headers']['Content-Type'] = 'application/merge-patch+json';
        $patched = $client->request('PATCH', "/api/games/{$uuid}", $options + [
            'json' => ['name' => 'Nancy Est', 'size' => 'L', 'edition' => 'imperial'],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('Nancy Est', $patched['name']);
        self::assertSame('L', $patched['size']);
        self::assertSame('imperial', $patched['edition']);
        self::assertSame(180, $patched['defaultHidingPeriodMinutes']);

        $fetched = $client->request('GET', "/api/games/{$uuid}", self::AUTH)->toArray();
        self::assertSame('Nancy Est', $fetched['name']);
        self::assertSame('L', $fetched['size']);
    }

    #[Test]
    public function itRejectsAStructuralChangeOnceTheRoundIsRunning(): void
    {
        $client = static::createClient();

        $created = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Nancy', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        $uuid = $created['uuid'];
        $roundUuid = $created['roundUuid'];
        self::assertIsString($uuid);
        self::assertIsString($roundUuid);

        $alice = $client->request('POST', "/api/games/{$uuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($alice['mercureToken']);
        $client->request('POST', "/api/rounds/{$roundUuid}/team", $this->headersWithToken($alice['mercureToken']) + [
            'json' => ['side' => 'hider'],
        ]);
        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($alice['mercureToken']) + ['json' => []]);
        self::assertResponseIsSuccessful();

        $options = $this->headersWithToken($alice['mercureToken']);
        $options['headers']['Content-Type'] = 'application/merge-patch+json';
        $rejected = $client->request('PATCH', "/api/games/{$uuid}", $options + [
            'json' => ['size' => 'S'],
        ])->toArray(false);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('game.structural_changes_blocked', $rejected['errorKey']);

        $unchanged = $client->request('GET', "/api/games/{$uuid}", self::AUTH)->toArray();
        self::assertSame('M', $unchanged['size']);
    }

    #[Test]
    public function itRenamesAGameEvenWhileTheRoundIsRunning(): void
    {
        $client = static::createClient();

        $created = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Nancy', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        $uuid = $created['uuid'];
        $roundUuid = $created['roundUuid'];
        self::assertIsString($uuid);
        self::assertIsString($roundUuid);

        $alice = $client->request('POST', "/api/games/{$uuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($alice['mercureToken']);
        $client->request('POST', "/api/rounds/{$roundUuid}/team", $this->headersWithToken($alice['mercureToken']) + [
            'json' => ['side' => 'hider'],
        ]);
        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($alice['mercureToken']) + ['json' => []]);
        self::assertResponseIsSuccessful();

        $options = $this->headersWithToken($alice['mercureToken']);
        $options['headers']['Content-Type'] = 'application/merge-patch+json';
        $patched = $client->request('PATCH', "/api/games/{$uuid}", $options + [
            'json' => ['name' => 'Nancy Ville'],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('Nancy Ville', $patched['name']);
    }

    #[Test]
    public function itIgnoresABoundaryInAConfigPatch(): void
    {
        $client = static::createClient();

        $created = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Nancy', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        $uuid = $created['uuid'];
        self::assertIsString($uuid);
        self::assertIsString($created['roundUuid']);

        $alice = $this->joinAndPickSide($client, $uuid, $created['roundUuid'], 'Alice', 'hider');

        $options = $this->headersWithToken($alice['token']);
        $options['headers']['Content-Type'] = 'application/merge-patch+json';
        $patched = $client->request('PATCH', "/api/games/{$uuid}", $options + [
            'json' => ['name' => 'Nancy Sud', 'boundarySwLat' => 48.6],
        ])->toArray(false);

        self::assertResponseIsSuccessful();
        self::assertSame('Nancy Sud', $patched['name']);
        self::assertNull($patched['boundarySwLat']);
    }

    #[Test]
    public function itReturns404PatchingAnUnknownGame(): void
    {
        static::createClient()->request('PATCH', '/api/games/00000000-0000-0000-0000-000000000000', [
            'headers' => ['X-API-KEY' => 'test-key', 'Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Ghost'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function onlyTheHostCanPatchTheGameConfig(): void
    {
        $client = static::createClient();

        $created = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Nancy', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        $uuid = $created['uuid'];
        self::assertIsString($uuid);
        self::assertIsString($created['roundUuid']);

        $alice = $this->joinAndPickSide($client, $uuid, $created['roundUuid'], 'Alice', 'hider');
        $bob = $this->joinAndPickSide($client, $uuid, $created['roundUuid'], 'Bob', 'seeker');

        $rejectedOptions = $this->headersWithToken($bob['token']);
        $rejectedOptions['headers']['Content-Type'] = 'application/merge-patch+json';
        $rejected = $client->request('PATCH', "/api/games/{$uuid}", $rejectedOptions + [
            'json' => ['name' => 'Nancy Ouest'],
        ])->toArray(false);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('game.only_host_can_delete', $rejected['errorKey']);

        $patchedOptions = $this->headersWithToken($alice['token']);
        $patchedOptions['headers']['Content-Type'] = 'application/merge-patch+json';
        $patched = $client->request('PATCH', "/api/games/{$uuid}", $patchedOptions + [
            'json' => ['name' => 'Nancy Ouest'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('Nancy Ouest', $patched['name']);
    }

    #[Test]
    public function aPlayerOfAnotherGameCannotPatchTheConfig(): void
    {
        $client = static::createClient();

        $created = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Nancy', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        $otherGame = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Paris', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($created['uuid']);
        self::assertIsString($created['roundUuid']);
        self::assertIsString($otherGame['uuid']);
        self::assertIsString($otherGame['roundUuid']);
        $this->joinAndPickSide($client, $created['uuid'], $created['roundUuid'], 'Alice', 'hider');
        $outsider = $this->joinAndPickSide($client, $otherGame['uuid'], $otherGame['roundUuid'], 'Eve', 'seeker');

        $options = $this->headersWithToken($outsider['token']);
        $options['headers']['Content-Type'] = 'application/merge-patch+json';
        $rejected = $client->request('PATCH', "/api/games/{$created['uuid']}", $options + [
            'json' => ['name' => 'Nancy Sud'],
        ])->toArray(false);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('game.only_host_can_delete', $rejected['errorKey']);
    }

    #[Test]
    public function patchingTheConfigRequiresASubscriberToken(): void
    {
        $client = static::createClient();
        $game = $this->createGame();

        $client->request('PATCH', "/api/games/{$game['uuid']}", [
            'headers' => ['X-API-KEY' => 'test-key', 'Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Ghost'],
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);
    }

    #[Test]
    public function itReturnsTheGamesSelectedTransitLines(): void
    {
        $client = static::createClient();

        $created = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Metz', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        $uuid = $created['uuid'];
        self::assertIsString($uuid);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $game = $em->getRepository(Game::class)->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(Game::class, $game);
        $em->persist(new GameTransitLine($game, 'relation', 12345, 'A', 'Ligne A', 'tram', 'Le Met', '#ff0000', 'TAMM'));
        $em->flush();

        $fetched = $client->request('GET', "/api/games/{$uuid}", self::AUTH)->toArray();

        self::assertArrayHasKey('selectedTransitLines', $fetched);
        $lines = $fetched['selectedTransitLines'];
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame('relation', $line['osmType']);
        self::assertSame(12345, $line['osmId']);
        self::assertSame('A', $line['ref']);
        self::assertSame('Ligne A', $line['name']);
        self::assertSame('tram', $line['routeType']);
        self::assertSame('#ff0000', $line['colour']);
    }

    #[Test]
    public function itAcceptsALineWhoseNetworkJoinsSeveralTariffUnions(): void
    {
        $network = 'Donau-Iller-Nahverkehrsverbund;Bodensee-Oberschwaben Verkehrsverbund;'
            . 'Verkehrsverbund Hegau-Bodensee;Waldshuter Tarifverbund;Regio Verkehrsverbund Lörrach';
        self::assertGreaterThan(120, mb_strlen($network));

        $client = static::createClient();
        $created = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Basel', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        $uuid = $created['uuid'];
        self::assertIsString($uuid);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $game = $em->getRepository(Game::class)->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(Game::class, $game);
        $em->persist(
            new GameTransitLine($game, 'relation', 10991886, 'S6', 'Basel - Zell', 'train', $network, '#0075a1', 'SBB'),
        );
        $em->flush();

        $fetched = $client->request('GET', "/api/games/{$uuid}", self::AUTH)->toArray();
        $lines = $fetched['selectedTransitLines'];
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame($network, $line['network']);
    }

    #[Test]
    public function itRejectsControlCharsAndOverlengthRefs(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Injection', 'size' => 'M', 'edition' => 'metric',
                'selectedTransitLines' => [[
                    'osmType' => 'relation',
                    'osmId' => 12345,
                    'ref' => "Ligne\x01A",
                ]]],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertViolationOn('selectedTransitLines[0][ref]', $client);

        $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Overlength', 'size' => 'M', 'edition' => 'metric',
                'selectedTransitLines' => [[
                    'osmType' => 'relation',
                    'osmId' => 12345,
                    'ref' => str_repeat('A', 51),
                ]]],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertViolationOn('selectedTransitLines[0][ref]', $client);
    }

    #[Test]
    public function itAcceptsRealWorldRefsAndAZeroOsmId(): void
    {
        $client = static::createClient();
        $created = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Real', 'size' => 'M', 'edition' => 'metric',
                'selectedTransitLines' => [[
                    'osmType' => 'relation',
                    'osmId' => 0,
                    'ref' => "x'; touch /tmp/pwn; echo '",
                ], [
                    'osmType' => 'way',
                    'osmId' => 12345,
                    'ref' => 'Ligne 1 (A/B) & C',
                    'name' => 'Line A',
                    'nameEn' => 'Line A (EN)',
                ]]],
        ])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Real', $created['name']);
    }

    #[Test]
    public function itRejectsABogusOsmTypeAndANonIntegerOsmId(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Bogus', 'size' => 'M', 'edition' => 'metric',
                'selectedTransitLines' => [[
                    'osmType' => 'bogus',
                    'osmId' => 'abc',
                    'ref' => 'A',
                ]]],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertViolationOn('selectedTransitLines[0][osmType]', $client);
        $this->assertViolationOn('selectedTransitLines[0][osmId]', $client);
    }

    #[Test]
    public function itRejectsMoreThanOneHundredSelectedLines(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Too Many', 'size' => 'M', 'edition' => 'metric',
                'selectedTransitLines' => array_fill(0, 101, [
                    'osmType' => 'relation',
                    'osmId' => 1,
                    'ref' => 'A',
                ])],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function itRejectsAGtfsSourceUuidThatIsNotAUuid(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Bad Gtfs', 'size' => 'M', 'edition' => 'metric',
                'selectedGtfsLines' => [[
                    'gtfsSourceUuid' => 'not-a-uuid',
                    'routeId' => 'R1',
                    'ref' => 'A',
                    'name' => 'Route A',
                ]]],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertViolationOn('selectedGtfsLines[0][gtfsSourceUuid]', $client);
    }

    private function assertViolationOn(string $propertyPath, Client $client): void
    {
        $response = $client->getResponse();
        self::assertNotNull($response);
        $data = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $violations = $data['violations'] ?? [];
        self::assertIsArray($violations);
        $paths = array_map(
            static fn (mixed $v): string => is_array($v) && is_string($v['propertyPath'] ?? null) ? $v['propertyPath'] : '',
            $violations,
        );
        self::assertContains($propertyPath, $paths);
    }
}
