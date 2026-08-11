<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase as PlatformApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

abstract class ApiTestCase extends PlatformApiTestCase
{
    /** Matches phpunit.dist.xml APP_API_KEY. */
    protected const array AUTH = ['headers' => ['X-API-KEY' => 'test-key']];

    /** Every test join shares this password; a dedicated test exercises a custom one. */
    protected const string JOIN_PASSWORD = 'test-password';

    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Account names are unique server-wide: a reset in one test would poison later same-name joins.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement('TRUNCATE TABLE accounts RESTART IDENTITY CASCADE');

        // Limiter state persists in the filesystem pool across tests in one kernel; each case starts clean.
        $cache = self::getContainer()->get('rate_limiter.cache');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
    }

    /**
     * Mints a real subscriber token for ANY player uuid, the adversarial-tests workhorse. The test
     * kernel signs with the same key the production service uses, so the token passes server-side checks.
     */
    protected function tokenFor(Client $client, string $playerUuid): string
    {
        $mercure = self::getContainer()->get(MercureJwtService::class);
        self::assertInstanceOf(MercureJwtService::class, $mercure);

        return $mercure->issueSubscriberToken([$mercure->playerEndgameTopic($playerUuid)], $playerUuid);
    }

    /**
     * Full happy-path session on the given client: join (returns playerUuid + token) -> team pick.
     *
     * @return array{0: string, 1: string, playerUuid: string, token: string}
     */
    protected function joinAndPickSide(
        Client $client,
        string $gameUuid,
        string $roundUuid,
        string $name,
        string $side,
    ): array {
        $join = $this->joinOn($client, $gameUuid, $name);
        $team = $this->pickSideOn($client, $roundUuid, $join['playerUuid'], $side, $join['mercureToken']);

        return [
            'playerUuid' => $join['playerUuid'],
            'token' => $team['mercureToken'],
            0 => $join['playerUuid'],
            1 => $team['mercureToken'],
        ];
    }

    /**
     * AUTH plus the subscriber-token header when one is given. References the shared header constant.
     *
     * @return array{headers: array<string, string>}
     */
    protected function headersWithToken(?string $subscriberToken): array
    {
        if ($subscriberToken === null) {
            return self::AUTH;
        }

        return ['headers' => self::AUTH['headers'] + [IdentityResolver::HEADER => $subscriberToken]];
    }

    /**
     * Legacy name for headersWithToken(), kept for the call sites that used it before the base class.
     *
     * @return array{headers: array<string, string>}
     */
    protected function authWith(string $subscriberToken): array
    {
        return $this->headersWithToken($subscriberToken);
    }

    /**
     * POST /api/games with a minimal payload; returns the decoded body (uuid, roundUuid, ...).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array{uuid: string, roundUuid: string}
     */
    protected function createGame(array $overrides = []): array
    {
        $game = $this->client()->request('POST', '/api/games', self::AUTH + [
            'json' => $overrides + ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);
        self::assertIsString($game['roundUuid']);

        return $game;
    }

    /**
     * POST /api/games/{uuid}/join; returns the join payload (playerUuid, mercureToken).
     *
     * @param array{uuid: string, roundUuid: string} $game
     *
     * @return array{playerUuid: string, mercureToken: string}
     */
    protected function joinGame(array $game, string $name): array
    {
        return $this->joinOn($this->client(), $game['uuid'], $name);
    }

    /**
     * POST /api/rounds/{uuid}/team with the caller's subscriber token; returns the re-minted-token payload.
     *
     * @param array{uuid: string, roundUuid: string} $game
     *
     * @return array{playerUuid: string, mercureToken: string, topics: list<string>, side: string, roundUuid: string}
     */
    protected function pickSide(
        array $game,
        string $playerUuid,
        string $side,
        ?string $subscriberToken = null,
    ): array {
        return $this->pickSideOn($this->client(), $game['roundUuid'], $playerUuid, $side, $subscriberToken);
    }

    /**
     * joinGame + pickSide in one call: a full player session.
     *
     * @param array{uuid: string, roundUuid: string} $game
     *
     * @return array{playerUuid: string, token: string}
     */
    protected function newPlayer(array $game, string $name, string $side): array
    {
        $session = $this->joinAndPickSide($this->client(), $game['uuid'], $game['roundUuid'], $name, $side);

        return ['playerUuid' => $session['playerUuid'], 'token' => $session['token']];
    }

    /**
     * The helpers share the test's own client when one exists: creating a fresh one per helper call
     * would move the response-assertion target (the last created client) away from the test's requests.
     */
    private function client(): Client
    {
        $browser = self::getClient();
        if ($browser instanceof KernelBrowser) {
            return new Client($browser);
        }

        return static::createClient();
    }

    /**
     * @return array{playerUuid: string, mercureToken: string, roundUuid: string}
     */
    private function joinOn(Client $client, string $gameUuid, string $name): array
    {
        $join = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => $name, 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($join['playerUuid']);
        self::assertIsString($join['mercureToken']);
        self::assertIsString($join['roundUuid']);
        self::assertArrayNotHasKey('joinSecret', $join);

        /** @var array{playerUuid: string, mercureToken: string, roundUuid: string} $join */
        return $join;
    }

    /**
     * @return array{playerUuid: string, mercureToken: string, topics: list<string>, side: string, roundUuid: string}
     */
    private function pickSideOn(
        Client $client,
        string $roundUuid,
        string $playerUuid,
        string $side,
        ?string $subscriberToken,
    ): array {
        $team = $client->request('POST', "/api/rounds/{$roundUuid}/team", $this->headersWithToken($subscriberToken) + [
            'json' => ['side' => $side],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame($playerUuid, $team['playerUuid']);
        self::assertIsString($team['mercureToken']);
        self::assertIsString($team['roundUuid']);
        self::assertIsArray($team['topics']);

        /** @var array{playerUuid: string, mercureToken: string, topics: list<string>, side: string, roundUuid: string} $team */
        return $team;
    }
}
