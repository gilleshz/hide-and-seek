<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use PHPUnit\Framework\Attributes\Test;

final class ChatReadApiTest extends ApiTestCase
{
    #[Test]
    public function itMarksMessagesReadUpToACursorAndReturnsTheWatermark(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken, $bob, $bobToken] = $this->createGameWithTwoPlayers($client);

        $message = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'Heading to the station');

        $cursor = $client->request('POST', "/api/games/{$gameUuid}/chat/read", $this->headersWithToken($bobToken) + [
            'json' => ['upToUuid' => $message['uuid']],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame($bob, $cursor['playerUuid']);
        self::assertSame('Bob', $cursor['playerName']);
        self::assertSameInstant($message['createdAt'], $cursor['readUpTo']);
    }

    #[Test]
    public function itListsReadCursorsForEveryPlayerWhoHasRead(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken, $bob, $bobToken] = $this->createGameWithTwoPlayers($client);

        $message = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'Anyone on the tram?');
        $this->markRead($client, $gameUuid, $bobToken, $message['uuid']);

        $cursors = $client->request('GET', "/api/games/{$gameUuid}/chat/read-cursors", self::AUTH)->toArray();

        self::assertResponseIsSuccessful();
        $members = $cursors['member'];
        self::assertIsArray($members);
        self::assertCount(1, $members);
        self::assertIsArray($members[0]);
        self::assertSame($bob, $members[0]['playerUuid']);
        self::assertSame('Bob', $members[0]['playerName']);
        self::assertSameInstant($message['createdAt'], $members[0]['readUpTo']);
    }

    #[Test]
    public function itReportsWhoReadASingleMessageAndWhen(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken, $bob, $bobToken] = $this->createGameWithTwoPlayers($client);

        $message = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'Photo incoming');
        $this->markRead($client, $gameUuid, $bobToken, $message['uuid']);

        $reads = $client->request(
            'GET',
            "/api/games/{$gameUuid}/chat/{$message['uuid']}/reads",
            self::AUTH,
        )->toArray();

        self::assertResponseIsSuccessful();
        $members = $reads['member'];
        self::assertIsArray($members);
        self::assertCount(1, $members);
        self::assertIsArray($members[0]);
        self::assertSame($bob, $members[0]['playerUuid']);
        self::assertSame('Bob', $members[0]['playerName']);
        self::assertIsString($members[0]['readAt']);
        self::assertNotSame('', $members[0]['readAt']);
    }

    #[Test]
    public function itNeverMarksAPlayersOwnMessagesAsReadByThemselves(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken] = $this->createGameWithTwoPlayers($client);

        $own = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'Talking to myself');

        $cursor = $client->request('POST', "/api/games/{$gameUuid}/chat/read", $this->headersWithToken($aliceToken) + [
            'json' => ['upToUuid' => $own['uuid']],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertNull($cursor['readUpTo']);

        $reads = $client->request(
            'GET',
            "/api/games/{$gameUuid}/chat/{$own['uuid']}/reads",
            self::AUTH,
        )->toArray();
        self::assertSame([], $reads['member']);
    }

    #[Test]
    public function itKeepsARepeatedReportIdempotent(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken, $bob, $bobToken] = $this->createGameWithTwoPlayers($client);

        $message = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'Only read once');
        $this->markRead($client, $gameUuid, $bobToken, $message['uuid']);
        $this->markRead($client, $gameUuid, $bobToken, $message['uuid']);

        $reads = $client->request(
            'GET',
            "/api/games/{$gameUuid}/chat/{$message['uuid']}/reads",
            self::AUTH,
        )->toArray();

        self::assertResponseIsSuccessful();
        $members = $reads['member'];
        self::assertIsArray($members);
        self::assertCount(1, $members);
    }

    #[Test]
    public function itKeepsTheWatermarkWhenAnOlderCursorIsReportedLate(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken, $bob, $bobToken] = $this->createGameWithTwoPlayers($client);

        $older = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'First');
        $newer = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'Second');

        $this->markRead($client, $gameUuid, $bobToken, $newer['uuid']);
        $late = $client->request('POST', "/api/games/{$gameUuid}/chat/read", $this->headersWithToken($bobToken) + [
            'json' => ['upToUuid' => $older['uuid']],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSameInstant($newer['createdAt'], $late['readUpTo']);
    }

    #[Test]
    public function itRejectsACursorPointingAtAnotherGamesMessage(): void
    {
        $client = static::createClient();
        [$gameUuid, , , $bob, $bobToken] = $this->createGameWithTwoPlayers($client);
        [$otherGameUuid, $otherPlayer, $otherToken] = $this->createGameWithTwoPlayers($client);

        $foreign = $this->postMessage($client, $otherGameUuid, $otherPlayer, $otherToken, 'Different game');

        $client->request('POST', "/api/games/{$gameUuid}/chat/read", $this->headersWithToken($bobToken) + [
            'json' => ['upToUuid' => $foreign['uuid']],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonContains(['detail' => 'Chat message not found in this game.']);
    }

    #[Test]
    public function itRejectsAReportFromAPlayerOfAnotherGame(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken] = $this->createGameWithTwoPlayers($client);
        [, , $outsiderToken] = $this->createGameWithTwoPlayers($client);

        $message = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'Not for you');

        $client->request('POST', "/api/games/{$gameUuid}/chat/read", $this->headersWithToken($outsiderToken) + [
            'json' => ['upToUuid' => $message['uuid']],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'Player does not belong to this game.']);
    }

    /**
     * The chat resource serializes its timestamp through the serializer and the receipt resources
     * format theirs explicitly, so the two agree on the instant but not on the offset notation.
     */
    private static function assertSameInstant(string $expected, mixed $actual): void
    {
        self::assertIsString($actual);
        self::assertEquals(new \DateTimeImmutable($expected), new \DateTimeImmutable($actual));
    }

    /**
     * @return array{string, string, string, string, string}
     */
    private function createGameWithTwoPlayers(Client $client): array
    {
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Zurich', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);

        $alice = $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($alice['playerUuid']);
        self::assertIsString($alice['mercureToken']);

        $bob = $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
            'json' => ['name' => 'Bob', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($bob['playerUuid']);
        self::assertIsString($bob['mercureToken']);

        return [$game['uuid'], $alice['playerUuid'], $alice['mercureToken'], $bob['playerUuid'], $bob['mercureToken']];
    }

    /**
     * @return array{uuid: string, createdAt: string}
     */
    private function postMessage(Client $client, string $gameUuid, string $playerUuid, string $token, string $body): array
    {
        $message = $client->request('POST', "/api/games/{$gameUuid}/chat", $this->headersWithToken($token) + [
            'json' => ['body' => $body],
        ])->toArray();

        self::assertIsString($message['uuid']);
        self::assertIsString($message['createdAt']);
        self::assertSame($playerUuid, $message['senderUuid']);

        return ['uuid' => $message['uuid'], 'createdAt' => $message['createdAt']];
    }

    private function markRead(Client $client, string $gameUuid, string $token, string $upToUuid): void
    {
        $client->request('POST', "/api/games/{$gameUuid}/chat/read", $this->headersWithToken($token) + [
            'json' => ['upToUuid' => $upToUuid],
        ]);

        self::assertResponseIsSuccessful();
    }
}
