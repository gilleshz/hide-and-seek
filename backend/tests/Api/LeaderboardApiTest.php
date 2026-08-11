<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Repository\RoundRepository;
use App\Service\RoundService;
use PHPUnit\Framework\Attributes\Test;

final class LeaderboardApiTest extends ApiTestCase
{
    #[Test]
    public function itReturnsAnEmptyLeaderboardForAGameWithoutAScoredRound(): void
    {
        $client = static::createClient();
        $game = $this->game($client);

        $leaderboard = $client->request('GET', "/api/games/{$game['uuid']}/leaderboard", self::AUTH)->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame([], $leaderboard['member']);
    }

    #[Test]
    public function itRanksTheScoredRoundsBestFirstWithTheirHidersAndBonuses(): void
    {
        $client = static::createClient();
        $game = $this->game($client);
        [, $aliceToken] = $this->join($client, $game['uuid'], 'Alice');
        [, $bobToken] = $this->join($client, $game['uuid'], 'Bob');

        $this->chooseSide($client, $game['roundUuid'], $aliceToken, 'hider');
        $this->chooseSide($client, $game['roundUuid'], $bobToken, 'seeker');
        $this->playRound($client, $game['roundUuid'], ['hidingSeconds' => 600, 'caught' => true], $aliceToken);

        // Sides swap next round (Bob is the hider), and only a hider may press the scored stop.
        $secondRoundUuid = $this->createNextRound($game['uuid']);
        $this->playRound($client, $secondRoundUuid, [
            'hidingSeconds' => 1200,
            'bonusMinutes' => 15,
            'bonusPercent' => 20,
            'caught' => true,
        ], $bobToken);

        $leaderboard = $client->request('GET', "/api/games/{$game['uuid']}/leaderboard", self::AUTH)->toArray();

        self::assertResponseIsSuccessful();
        $entries = $leaderboard['member'];
        self::assertIsArray($entries);
        self::assertCount(2, $entries);
        $best = $entries[0];
        $runnerUp = $entries[1];
        self::assertIsArray($best);
        self::assertIsArray($runnerUp);

        // 1200 raw + 15 min flat + 20% of 1200 beats the first round's bare 600.
        self::assertSame($secondRoundUuid, $best['roundUuid']);
        self::assertSame(2, $best['roundNumber']);
        self::assertSame(['Bob'], $best['hiderNames']);
        self::assertSame(1200, $best['hidingTimeSeconds']);
        self::assertSame(1200 + 900 + 240, $best['scoreSeconds']);
        self::assertSame(15, $best['bonusMinutes']);
        self::assertSame(20, $best['bonusPercent']);

        self::assertSame($game['roundUuid'], $runnerUp['roundUuid']);
        self::assertSame(1, $runnerUp['roundNumber']);
        self::assertSame(['Alice'], $runnerUp['hiderNames']);
        self::assertSame(600, $runnerUp['hidingTimeSeconds']);
        self::assertSame(600, $runnerUp['scoreSeconds']);
    }

    #[Test]
    public function itExcludesARoundThatWasAborted(): void
    {
        $client = static::createClient();
        $game = $this->game($client);
        [, $aliceToken] = $this->join($client, $game['uuid'], 'Alice');

        $this->chooseSide($client, $game['roundUuid'], $aliceToken, 'hider');
        $this->playRound($client, $game['roundUuid'], ['hidingSeconds' => 600, 'caught' => true], $aliceToken);

        $abortedRoundUuid = $this->createNextRound($game['uuid']);
        $this->playRound($client, $abortedRoundUuid, [], $aliceToken);

        $leaderboard = $client->request('GET', "/api/games/{$game['uuid']}/leaderboard", self::AUTH)->toArray();

        self::assertResponseIsSuccessful();
        $entries = $leaderboard['member'];
        self::assertIsArray($entries);
        self::assertCount(1, $entries);
        $only = $entries[0];
        self::assertIsArray($only);
        self::assertSame($game['roundUuid'], $only['roundUuid']);
        self::assertSame(1, $only['roundNumber']);
    }

    #[Test]
    public function itReturns404ForAnUnknownGame(): void
    {
        $client = static::createClient();

        $response = $client->request(
            'GET',
            '/api/games/00000000-0000-0000-0000-000000000000/leaderboard',
            self::AUTH,
        )->toArray(false);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('game.not_found', $response['errorKey']);
    }

    /**
     * @return array{uuid: string, roundUuid: string}
     */
    private function game(Client $client): array
    {
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'S', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);
        self::assertIsString($game['roundUuid']);

        return ['uuid' => $game['uuid'], 'roundUuid' => $game['roundUuid']];
    }

    /**
     * @return array{string, string}
     */
    private function join(Client $client, string $gameUuid, string $name): array
    {
        $join = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => $name, 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($join['playerUuid']);
        self::assertIsString($join['mercureToken']);

        return [$join['playerUuid'], $join['mercureToken']];
    }

    private function chooseSide(Client $client, string $roundUuid, string $token, string $side): void
    {
        $client->request('POST', "/api/rounds/{$roundUuid}/team", $this->headersWithToken($token) + [
            'json' => ['side' => $side],
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, int|bool> $stop
     */
    private function playRound(Client $client, string $roundUuid, array $stop, string $token): void
    {
        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($token) + ['json' => []]);
        self::assertResponseIsSuccessful();

        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);
        // The stop declares the frozen hiding time, so the seeking clock has to have run at least that long.
        $round->setHidingPeriodEndsAt(new \DateTimeImmutable('-2 hours'));
        $rounds->save($round);

        $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($token) + ['json' => $stop]);
        self::assertResponseIsSuccessful();
    }

    // POST /games/{uuid}/rounds has a pre-existing IRI-generation 500 (response only); create via the service.
    private function createNextRound(string $gameUuid): string
    {
        /** @var RoundService $roundService */
        $roundService = self::getContainer()->get(RoundService::class);

        return $roundService->createNextRound($gameUuid)->getUuid();
    }
}
