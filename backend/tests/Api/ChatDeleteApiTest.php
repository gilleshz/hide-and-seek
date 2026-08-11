<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Storage\ImageStorageInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ChatDeleteApiTest extends ApiTestCase
{
    #[Test]
    public function itDeletesTheSendersOwnMessageAndKeepsItInHistoryWithoutContent(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken] = $this->createGameWithTwoPlayers($client);

        $message = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'Meet me at the tower');

        $client->request('DELETE', "/api/games/{$gameUuid}/chat/{$message}", $this->headersWithToken($aliceToken));

        self::assertResponseStatusCodeSame(204);
        $deleted = $this->findMessage($client, $gameUuid, $message);
        self::assertTrue($deleted['deleted']);
        self::assertNull($deleted['body']);
        self::assertSame($alice, $deleted['senderUuid']);
    }

    #[Test]
    public function itRejectsDeletingAMessageSentBySomeoneElse(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken, $bob, $bobToken] = $this->createGameWithTwoPlayers($client);

        $message = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'Not yours to delete');

        $client->request('DELETE', "/api/games/{$gameUuid}/chat/{$message}", $this->headersWithToken($bobToken));

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'Only the sender of a message can delete it.']);
        self::assertFalse($this->findMessage($client, $gameUuid, $message)['deleted']);
    }

    #[Test]
    public function itTreatsASecondDeleteOfTheSameMessageAsANoOp(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken] = $this->createGameWithTwoPlayers($client);

        $message = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'Twice deleted');
        $path = "/api/games/{$gameUuid}/chat/{$message}";

        $client->request('DELETE', $path, $this->headersWithToken($aliceToken));
        self::assertResponseStatusCodeSame(204);

        $client->request('DELETE', $path, $this->headersWithToken($aliceToken));

        self::assertResponseStatusCodeSame(204);
        self::assertTrue($this->findMessage($client, $gameUuid, $message)['deleted']);
    }

    #[Test]
    public function itKeepsTheReplyLinkOfAMessageThatQuotesADeletedOne(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken, $bob, $bobToken] = $this->createGameWithTwoPlayers($client);

        $original = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'Original');
        $reply = $client->request('POST', "/api/games/{$gameUuid}/chat", $this->headersWithToken($bobToken) + [
            'json' => ['body' => 'Quoting you', 'replyToUuid' => $original],
        ])->toArray();
        self::assertIsString($reply['uuid']);

        $client->request('DELETE', "/api/games/{$gameUuid}/chat/{$original}", $this->headersWithToken($aliceToken));
        self::assertResponseStatusCodeSame(204);

        self::assertSame($original, $this->findMessage($client, $gameUuid, $reply['uuid'])['replyToUuid']);
    }

    #[Test]
    public function itRemovesTheStoredFileWhenAnImageMessageIsDeleted(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken] = $this->createGameWithTwoPlayers($client);

        $message = $client->request('POST', "/api/games/{$gameUuid}/chat/image", [
            'headers' => ['Content-Type' => 'multipart/form-data'] + $this->headersWithToken($aliceToken)['headers'],
            'extra' => [
                'parameters' => ['caption' => 'Too revealing'],
                'files' => ['image' => $this->createPngUpload()],
            ],
        ])->toArray();
        self::assertIsString($message['uuid']);
        self::assertIsString($message['imageRef']);

        $client->request(
            'DELETE',
            "/api/games/{$gameUuid}/chat/{$message['uuid']}",
            $this->headersWithToken($aliceToken),
        );
        self::assertResponseStatusCodeSame(204);

        $deleted = $this->findMessage($client, $gameUuid, $message['uuid']);
        self::assertTrue($deleted['deleted']);
        self::assertNull($deleted['imageRef']);

        $storage = static::getContainer()->get(ImageStorageInterface::class);
        self::assertInstanceOf(ImageStorageInterface::class, $storage);
        $this->expectException(\RuntimeException::class);
        $storage->read($gameUuid, $message['imageRef']);
    }

    #[Test]
    public function itReturns404DeletingAMessageOfAnotherGame(): void
    {
        $client = static::createClient();
        [$gameUuid, , $aliceToken] = $this->createGameWithTwoPlayers($client);
        [$otherGameUuid, $otherAlice, $otherToken] = $this->createGameWithTwoPlayers($client);

        $elsewhere = $this->postMessage($client, $otherGameUuid, $otherAlice, $otherToken, 'In another game');

        $client->request('DELETE', "/api/games/{$gameUuid}/chat/{$elsewhere}", $this->headersWithToken($aliceToken));

        self::assertResponseStatusCodeSame(404);
        self::assertJsonContains(['detail' => 'Chat message not found in this game.']);
    }

    #[Test]
    public function itRejectsADeleteWithoutASubscriberToken(): void
    {
        $client = static::createClient();
        [$gameUuid, $alice, $aliceToken] = $this->createGameWithTwoPlayers($client);

        $message = $this->postMessage($client, $gameUuid, $alice, $aliceToken, 'Who is asking?');

        $client->request('DELETE', "/api/games/{$gameUuid}/chat/{$message}", self::AUTH);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);
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

    private function postMessage(Client $client, string $gameUuid, string $playerUuid, string $token, string $body): string
    {
        $message = $client->request('POST', "/api/games/{$gameUuid}/chat", $this->headersWithToken($token) + [
            'json' => ['body' => $body],
        ])->toArray();

        self::assertIsString($message['uuid']);
        self::assertSame($playerUuid, $message['senderUuid']);

        return $message['uuid'];
    }

    /**
     * @return array<string, mixed>
     */
    private function findMessage(Client $client, string $gameUuid, string $messageUuid): array
    {
        foreach ($this->history($client, $gameUuid) as $message) {
            if ($message['uuid'] === $messageUuid) {
                return $message;
            }
        }

        self::fail("Message {$messageUuid} is missing from the chat history.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(Client $client, string $gameUuid): array
    {
        $history = $client->request('GET', "/api/games/{$gameUuid}/chat", self::AUTH)->toArray();
        self::assertResponseIsSuccessful();
        $members = $history['member'];
        self::assertIsArray($members);

        /** @var list<array<string, mixed>> $members */
        return $members;
    }

    private function createPngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'chat_png_');
        self::assertIsString($path);
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertIsString($png);
        file_put_contents($path, $png);

        return new UploadedFile($path, 'small.png', 'image/png', null, true);
    }
}
