<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\AskedQuestion;
use App\Enum\QuestionCategory;
use App\Repository\AskedQuestionRepository;
use App\Repository\PlayerRepository;
use App\Repository\PossibleAreaConstraintRepository;
use App\Repository\RoundRepository;
use PHPUnit\Framework\Attributes\Test;

final class RoundApiTest extends ApiTestCase
{
    #[Test]
    public function itReturnsALobbyRoundBeforeItIsStarted(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'S', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];

        $round = $client->request('GET', "/api/rounds/{$roundUuid}", self::AUTH)->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('lobby', $round['status']);
        self::assertNull($round['hidingPeriodStartedAt']);
        self::assertNull($round['hidingTimeSeconds']);
    }

    #[Test]
    public function itStartsARoundAndRejectsStartingItAgain(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $token = $this->seekerToken($client, $game);

        $started = $client->request('POST', "/api/rounds/{$game['roundUuid']}/start", $this->headersWithToken($token) + [
            'json' => [],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('hiding', $started['status']);
        self::assertNotNull($started['hidingPeriodStartedAt']);
        self::assertNotNull($started['hidingPeriodEndsAt']);

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/start", $this->headersWithToken($token) + ['json' => []]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function itStopsARoundMidHidingWithoutPostingAHidingTime(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'S', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        $token = $this->seekerToken($client, $game);

        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($token) + ['json' => []]);
        $stopped = $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($token) + ['json' => []])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('ended', $stopped['status']);
        self::assertFalse($stopped['caught']);
        self::assertNull($stopped['seekingEndedAt']);
        self::assertNull($stopped['hidingTimeSeconds']);

        // The player's own join announcement is all the chat holds: no hiding-time message was posted.
        $chat = $client->request('GET', "/api/games/{$game['uuid']}/chat", self::AUTH)->toArray();
        $messages = $chat['member'];
        self::assertIsArray($messages);
        self::assertCount(1, $messages);
        self::assertIsArray($messages[0]);
        self::assertSame('system.player_joined', $messages[0]['bodyKey'] ?? null);
    }

    #[Test]
    public function itAbortsARoundMidSeekingWithoutScoringItOrPostingAHidingTime(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'S', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        $token = $this->seekerToken($client, $game);

        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($token) + ['json' => []]);
        $this->elapseHidingPeriod($roundUuid);

        $stopped = $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($token) + ['json' => []])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('ended', $stopped['status']);
        self::assertFalse($stopped['caught']);
        self::assertNull($stopped['seekingEndedAt']);
        self::assertNull($stopped['hidingTimeSeconds']);
        self::assertNull($stopped['scoreSeconds']);

        // The player's own join announcement is all the chat holds: no hiding-time message was posted.
        $chat = $client->request('GET', "/api/games/{$game['uuid']}/chat", self::AUTH)->toArray();
        $messages = $chat['member'];
        self::assertIsArray($messages);
        self::assertCount(1, $messages);
        self::assertIsArray($messages[0]);
        self::assertSame('system.player_joined', $messages[0]['bodyKey'] ?? null);
    }

    #[Test]
    public function itRejectsStoppingAnAlreadyEndedRound(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $roundUuid = $game['roundUuid'];
        $token = $this->seekerToken($client, $game);

        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($token) + ['json' => []]);
        $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($token) + ['json' => []]);
        self::assertResponseIsSuccessful();

        $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($token) + ['json' => []]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function itRejectsStoppingARoundThatNeverStarted(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $roundUuid = $game['roundUuid'];
        $token = $this->seekerToken($client, $game);

        $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($token) + ['json' => []]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function itCreatesTheNextRoundUnderTheDefaultJsonLdAccept(): void
    {
        $client = static::createClient();
        [$gameUuid, $token] = $this->createGameWithEndedRound($client);

        $created = $client->request('POST', "/api/games/{$gameUuid}/rounds", $this->headersWithToken($token))->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($created['roundUuid']);
        self::assertSame("/api/rounds/{$created['roundUuid']}", $created['@id']);
        self::assertSame('lobby', $created['status']);
    }

    #[Test]
    public function itCreatesTheNextRoundWithTheShapeTheAppExpectsUnderPlainJson(): void
    {
        $client = static::createClient();
        [$gameUuid, $token] = $this->createGameWithEndedRound($client);

        $created = $client->request('POST', "/api/games/{$gameUuid}/rounds", [
            'headers' => ['Accept' => 'application/json'] + $this->headersWithToken($token)['headers'],
        ])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertArrayNotHasKey('@id', $created);
        self::assertIsString($created['roundUuid']);
        self::assertSame('lobby', $created['status']);
        self::assertNull($created['hidingPeriodStartedAt']);
        self::assertNull($created['hidingPeriodEndsAt']);
        self::assertNull($created['seekingEndedAt']);
        self::assertNull($created['hidingTimeSeconds']);
    }

    #[Test]
    public function itStopsARoundOnceTheHidingPeriodHasElapsedAndPostsTheHidingTimeToChat(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'S', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);
        self::assertIsString($game['roundUuid']);
        $gameUuid = $game['uuid'];
        $roundUuid = $game['roundUuid'];
        $token = $this->hiderToken($client, $game);

        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($token) + ['json' => []]);

        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);
        $round->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 minute'));
        $rounds->save($round);

        $stopped = $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($token) + [
            'json' => ['caught' => true],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('ended', $stopped['status']);
        self::assertTrue($stopped['caught']);
        self::assertIsInt($stopped['hidingTimeSeconds']);

        $chat = $client->request('GET', "/api/games/{$gameUuid}/chat", self::AUTH)->toArray();
        $messages = $chat['member'];
        self::assertIsArray($messages);
        $last = end($messages);
        self::assertIsArray($last);
        self::assertNull($last['senderUuid']);
        self::assertNull($last['senderName']);
        self::assertIsString($last['body']);
        self::assertStringContainsString('Hiding time:', $last['body']);
    }

    #[Test]
    public function itScoresTheTimeBonusesDeclaredWhenTheHidersStopTheRound(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'S', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        $token = $this->hiderToken($client, $game);

        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($token) + ['json' => []]);

        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);
        $round->setHidingPeriodEndsAt(new \DateTimeImmutable('-60 minutes'));
        $rounds->save($round);

        $stopped = $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($token) + [
            'json' => ['bonusMinutes' => 15, 'bonusPercent' => 20, 'caught' => true],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(15, $stopped['bonusMinutes']);
        self::assertSame(20, $stopped['bonusPercent']);
        self::assertIsInt($stopped['hidingTimeSeconds']);
        self::assertIsInt($stopped['scoreSeconds']);
        // 60 min raw + 15 flat + 20% of 60 min, give or take the second the request takes.
        self::assertSame(87, intdiv($stopped['scoreSeconds'], 60));

        $chat = $client->request('GET', "/api/games/{$game['uuid']}/chat", self::AUTH)->toArray();
        $messages = $chat['member'];
        self::assertIsArray($messages);
        $last = end($messages);
        self::assertIsArray($last);
        self::assertSame('round.hiding_time_with_bonus', $last['bodyKey']);
        self::assertIsArray($last['bodyArgs']);
        // Raw seconds travel instead of a formatted clock so each client words the score in its locale.
        self::assertIsInt($last['bodyArgs']['totalSeconds']);
        self::assertSame(87, intdiv($last['bodyArgs']['totalSeconds'], 60));
        self::assertSame(0, $last['bodyArgs']['trapMin']);
        self::assertSame($stopped['scoreSeconds'], $last['bodyArgs']['totalSeconds']);
    }

    #[Test]
    public function itRejectsANegativeTimeBonus(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $roundUuid = $game['roundUuid'];
        $token = $this->seekerToken($client, $game);

        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($token) + ['json' => []]);
        $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($token) + [
            'json' => ['bonusMinutes' => -5],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function itStartsTheNextRoundWithAFullPossibleAreaAndNoQuestions(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'S', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        $session = $this->joinAndPickSide($client, $game['uuid'], $roundUuid, 'Alice', 'seeker');
        $token = $session['token'];

        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($token) + ['json' => []]);
        $this->seedConstraintAndQuestion($roundUuid, $session['playerUuid']);

        $before = $client->request('GET', "/api/rounds/{$roundUuid}/possible-area", $this->headersWithToken($token))->toArray();
        self::assertNotNull($before['geoJson']);

        $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($token) + ['json' => []]);
        self::assertResponseIsSuccessful();

        $next = $client->request('POST', "/api/games/{$game['uuid']}/rounds", $this->headersWithToken($token))->toArray();
        self::assertIsString($next['roundUuid']);
        self::assertNotSame($roundUuid, $next['roundUuid']);

        $area = $client->request('GET', "/api/rounds/{$next['roundUuid']}/possible-area", $this->headersWithToken($token))->toArray();
        self::assertNull($area['geoJson']);

        $questions = $client->request('GET', "/api/rounds/{$next['roundUuid']}/questions", self::AUTH)->toArray();
        self::assertSame([], $questions['member']);

        // The aborted run stays queryable; nothing was deleted, only superseded.
        $old = $client->request('GET', "/api/rounds/{$roundUuid}/possible-area", $this->headersWithToken($token))->toArray();
        self::assertNotNull($old['geoJson']);
    }

    #[Test]
    public function itStartsARoundWithACustomHidingPeriod(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $token = $this->seekerToken($client, $game);

        $started = $client->request('POST', "/api/rounds/{$game['roundUuid']}/start", $this->headersWithToken($token) + [
            'json' => ['hidingPeriodMinutes' => 5],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('hiding', $started['status']);
        self::assertSame(300, $this->hidingPeriodSeconds($started));
    }

    #[Test]
    public function itStartsARoundWithTheSizeDefaultWhenNoDurationIsSent(): void
    {
        $client = static::createClient();
        $game = $this->createGame(['size' => 'S']);
        $token = $this->seekerToken($client, $game);

        $started = $client->request('POST', "/api/rounds/{$game['roundUuid']}/start", $this->headersWithToken($token) + [
            'json' => [],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(30 * 60, $this->hidingPeriodSeconds($started));
    }

    #[Test]
    public function itRejectsAZeroHidingPeriod(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $token = $this->seekerToken($client, $game);

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/start", $this->headersWithToken($token) + [
            'json' => ['hidingPeriodMinutes' => 0],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param array<mixed> $started
     */
    private function hidingPeriodSeconds(array $started): int
    {
        self::assertIsString($started['hidingPeriodStartedAt']);
        self::assertIsString($started['hidingPeriodEndsAt']);

        return new \DateTimeImmutable($started['hidingPeriodEndsAt'])->getTimestamp()
            - new \DateTimeImmutable($started['hidingPeriodStartedAt'])->getTimestamp();
    }

    /**
     * @param array{uuid: string, roundUuid: string} $game
     */
    private function seekerToken(Client $client, array $game): string
    {
        return $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'seeker')['token'];
    }

    /**
     * @param array{uuid: string, roundUuid: string} $game
     */
    private function hiderToken(Client $client, array $game): string
    {
        return $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Alice', 'hider')['token'];
    }

    private function elapseHidingPeriod(string $roundUuid): void
    {
        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);
        $round->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 minute'));
        $rounds->save($round);
    }

    private function seedConstraintAndQuestion(string $roundUuid, string $playerUuid): void
    {
        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);

        /** @var PlayerRepository $players */
        $players = self::getContainer()->get(PlayerRepository::class);
        $player = $players->findOneByUuid($playerUuid);
        self::assertNotNull($player);

        /** @var PossibleAreaConstraintRepository $constraints */
        $constraints = self::getContainer()->get(PossibleAreaConstraintRepository::class);
        $constraints->insertConstraint(
            $round,
            'POLYGON((13.3 52.4, 13.5 52.4, 13.5 52.6, 13.3 52.6, 13.3 52.4))',
            'Radar (1000m): yes',
        );

        /** @var AskedQuestionRepository $questions */
        $questions = self::getContainer()->get(AskedQuestionRepository::class);
        $questions->save(new AskedQuestion($round, $player, QuestionCategory::Radar, null));
    }

    /**
     * @return array{string, string}
     */
    private function createGameWithEndedRound(Client $client): array
    {
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'S', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        $token = $this->seekerToken($client, $game);

        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($token) + ['json' => []]);

        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);
        $round->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 minute'));
        $rounds->save($round);

        $client->request('POST', "/api/rounds/{$roundUuid}/stop", $this->headersWithToken($token) + ['json' => []]);
        self::assertResponseIsSuccessful();

        return [$game['uuid'], $token];
    }
}
