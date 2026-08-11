<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ChatApiTest extends ApiTestCase
{
    #[Test]
    public function itPostsAndListsChatMessagesChronologically(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);
        $gameUuid = $game['uuid'];

        $join = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($join['playerUuid']);
        self::assertIsString($join['mercureToken']);
        $playerUuid = $join['playerUuid'];
        $token = $join['mercureToken'];

        $first = $client->request('POST', "/api/games/{$gameUuid}/chat", $this->headersWithToken($token) + [
            'json' => ['body' => 'Hello everyone'],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('text', $first['type']);
        self::assertSame($playerUuid, $first['senderUuid']);
        self::assertSame('Alice', $first['senderName']);
        self::assertSame('Hello everyone', $first['body']);

        $client->request('POST', "/api/games/{$gameUuid}/chat", $this->headersWithToken($token) + [
            'json' => ['body' => 'Second message'],
        ]);

        $history = $client->request('GET', "/api/games/{$gameUuid}/chat", self::AUTH)->toArray();

        self::assertResponseIsSuccessful();
        $messages = $history['member'];
        self::assertIsArray($messages);
        // Joining announces itself, so the history opens with a system message.
        self::assertCount(3, $messages);
        self::assertIsArray($messages[0]);
        self::assertIsArray($messages[1]);
        self::assertIsArray($messages[2]);
        self::assertSame('system', $messages[0]['type']);
        self::assertSame('system.player_joined', $messages[0]['bodyKey']);
        self::assertSame(['name' => 'Alice'], $messages[0]['bodyArgs']);
        self::assertSame('Hello everyone', $messages[1]['body']);
        self::assertSame('Alice', $messages[1]['senderName']);
        self::assertSame('Second message', $messages[2]['body']);
    }

    #[Test]
    public function itPostsAMessageWithReplyToUuid(): void
    {
        $client = static::createClient();
        [$gameUuid, , $token] = $this->createGameWithPlayer($client);

        $first = $client->request('POST', "/api/games/{$gameUuid}/chat", $this->headersWithToken($token) + [
            'json' => ['body' => 'First message'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertIsString($first['uuid']);

        $reply = $client->request('POST', "/api/games/{$gameUuid}/chat", $this->headersWithToken($token) + [
            'json' => ['body' => 'Replying', 'replyToUuid' => $first['uuid']],
        ])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('text', $reply['type']);
        self::assertSame('Replying', $reply['body']);
        self::assertSame($first['uuid'], $reply['replyToUuid']);
    }

    #[Test]
    public function itPostsAnImageMessage(): void
    {
        $client = static::createClient();
        [$gameUuid, $playerUuid, $token] = $this->createGameWithPlayer($client);

        $message = $client->request('POST', "/api/games/{$gameUuid}/chat/image", [
            'headers' => ['Content-Type' => 'multipart/form-data'] + $this->headersWithToken($token)['headers'],
            'extra' => [
                'parameters' => ['caption' => 'Look at this'],
                'files' => ['image' => $this->createPngUpload()],
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('image', $message['type']);
        self::assertSame($playerUuid, $message['senderUuid']);
        self::assertSame('Look at this', $message['body']);
        self::assertIsString($message['imageRef']);
        self::assertNotSame('', $message['imageRef']);
    }

    #[Test]
    public function itPostsAnImageMessageThatRepliesToAnEarlierMessage(): void
    {
        $client = static::createClient();
        [$gameUuid, , $token] = $this->createGameWithPlayer($client);

        $original = $client->request('POST', "/api/games/{$gameUuid}/chat", $this->headersWithToken($token) + [
            'json' => ['body' => 'Where are you?'],
        ])->toArray();
        self::assertIsString($original['uuid']);

        $reply = $client->request('POST', "/api/games/{$gameUuid}/chat/image", [
            'headers' => ['Content-Type' => 'multipart/form-data'] + $this->headersWithToken($token)['headers'],
            'extra' => [
                'parameters' => [
                    'caption' => 'Right here',
                    'replyToUuid' => $original['uuid'],
                ],
                'files' => ['image' => $this->createPngUpload()],
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('image', $reply['type']);
        self::assertSame('Right here', $reply['body']);
        self::assertSame($original['uuid'], $reply['replyToUuid']);
        self::assertIsString($reply['imageRef']);
    }

    #[Test]
    public function itRejectsAnImageCaptionLongerThanTheBodyColumn(): void
    {
        $client = static::createClient();
        [$gameUuid, , $token] = $this->createGameWithPlayer($client);

        $client->request('POST', "/api/games/{$gameUuid}/chat/image", [
            'headers' => ['Content-Type' => 'multipart/form-data'] + $this->headersWithToken($token)['headers'],
            'extra' => [
                'parameters' => ['caption' => str_repeat('a', 2001)],
                'files' => ['image' => $this->createPngUpload()],
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'Caption exceeds maximum length of 2000 characters.']);
    }

    #[Test]
    public function itRejectsAnImageExceedingThePhpUploadLimitWithTheSizeMessage(): void
    {
        $client = static::createClient();
        [$gameUuid, , $token] = $this->createGameWithPlayer($client);

        $tooBig = new UploadedFile(
            $this->createPngUpload()->getPathname(),
            'huge.png',
            'image/png',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        $client->request('POST', "/api/games/{$gameUuid}/chat/image", [
            'headers' => ['Content-Type' => 'multipart/form-data'] + $this->headersWithToken($token)['headers'],
            'extra' => [
                'parameters' => [],
                'files' => ['image' => $tooBig],
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'Image exceeds maximum size of 8 MB.']);
    }

    #[Test]
    public function itServesAnUploadedImageWithImmutableCacheHeaders(): void
    {
        $client = static::createClient();
        [$gameUuid, , $token] = $this->createGameWithPlayer($client);

        $message = $client->request('POST', "/api/games/{$gameUuid}/chat/image", [
            'headers' => ['Content-Type' => 'multipart/form-data'] + $this->headersWithToken($token)['headers'],
            'extra' => [
                'parameters' => [],
                'files' => ['image' => $this->createPngUpload()],
            ],
        ])->toArray();
        self::assertIsString($message['imageRef']);

        $client->request('GET', "/api/games/{$gameUuid}/chat/image/{$message['imageRef']}", self::AUTH);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'image/png');
        self::assertResponseHeaderSame('cache-control', 'immutable, max-age=31536000, private');
    }

    #[Test]
    public function aPhpNamedPolyglotIsReEncodedSoItsPayloadNeverSurvives(): void
    {
        $client = static::createClient();
        [$gameUuid, , $token] = $this->createGameWithPlayer($client);

        $message = $client->request('POST', "/api/games/{$gameUuid}/chat/image", [
            'headers' => ['Content-Type' => 'multipart/form-data'] + $this->headersWithToken($token)['headers'],
            'extra' => [
                'parameters' => [],
                'files' => ['image' => $this->createJpegPolyglotUpload()],
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertIsString($message['imageRef']);
        self::assertStringEndsWith('.jpg', $message['imageRef']);

        $client->request('GET', "/api/games/{$gameUuid}/chat/image/{$message['imageRef']}", self::AUTH);
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        self::assertStringNotContainsString('<?php', $response->getContent());
    }

    #[Test]
    public function aPhpNamedPngUploadGetsTheDerivedPngExtension(): void
    {
        $client = static::createClient();
        [$gameUuid, , $token] = $this->createGameWithPlayer($client);

        $message = $client->request('POST', "/api/games/{$gameUuid}/chat/image", [
            'headers' => ['Content-Type' => 'multipart/form-data'] + $this->headersWithToken($token)['headers'],
            'extra' => [
                'parameters' => [],
                'files' => ['image' => $this->createPolyglotUpload('png')],
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertIsString($message['imageRef']);
        self::assertStringEndsWith('.png', $message['imageRef']);
    }

    #[Test]
    public function aPhpNamedWebpUploadGetsTheDerivedWebpExtension(): void
    {
        $client = static::createClient();
        [$gameUuid, , $token] = $this->createGameWithPlayer($client);

        $message = $client->request('POST', "/api/games/{$gameUuid}/chat/image", [
            'headers' => ['Content-Type' => 'multipart/form-data'] + $this->headersWithToken($token)['headers'],
            'extra' => [
                'parameters' => [],
                'files' => ['image' => $this->createPolyglotUpload('webp')],
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertIsString($message['imageRef']);
        self::assertStringEndsWith('.webp', $message['imageRef']);
    }

    #[Test]
    public function itRejectsANonImageUploadWithTheMimeMessage(): void
    {
        $client = static::createClient();
        [$gameUuid, , $token] = $this->createGameWithPlayer($client);

        $text = tempnam(sys_get_temp_dir(), 'chat_text_');
        self::assertIsString($text);
        file_put_contents($text, 'definitely not an image');

        $client->request('POST', "/api/games/{$gameUuid}/chat/image", [
            'headers' => ['Content-Type' => 'multipart/form-data'] + $this->headersWithToken($token)['headers'],
            'extra' => [
                'parameters' => [],
                'files' => ['image' => new UploadedFile($text, 'note.txt', 'text/plain', null, true)],
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'Only JPEG, PNG, and WebP images are accepted.']);
    }

    #[Test]
    public function itAnnouncesADepartureAndKeepsWhatThePlayerWrote(): void
    {
        $client = static::createClient();
        [$gameUuid, $playerUuid, $token] = $this->createGameWithPlayer($client);

        $client->request('POST', "/api/games/{$gameUuid}/chat", $this->headersWithToken($token) + [
            'json' => ['body' => 'Heading home'],
        ]);
        $client->request('POST', "/api/games/{$gameUuid}/leave", $this->headersWithToken($token));
        self::assertResponseIsSuccessful();

        $history = $client->request('GET', "/api/games/{$gameUuid}/chat", self::AUTH)->toArray();
        $messages = $history['member'];
        self::assertIsArray($messages);
        self::assertCount(3, $messages);
        self::assertIsArray($messages[1]);
        self::assertIsArray($messages[2]);

        // Leaving must not take the player's own messages with it.
        self::assertSame('Heading home', $messages[1]['body']);
        self::assertSame('Alice', $messages[1]['senderName']);
        self::assertSame($playerUuid, $messages[1]['senderUuid']);
        self::assertSame('system.player_left', $messages[2]['bodyKey']);
        self::assertSame(['name' => 'Alice'], $messages[2]['bodyArgs']);
    }

    #[Test]
    public function aPlayerWhoLeavesAndComesBackOwnsTheirOldMessagesAgain(): void
    {
        $client = static::createClient();
        [$gameUuid] = $this->createGameWithPlayer($client);

        $carol = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Carol', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($carol['playerUuid']);
        self::assertIsString($carol['mercureToken']);
        self::assertArrayNotHasKey('joinSecret', $carol);
        $client->request('POST', "/api/games/{$gameUuid}/chat", $this->headersWithToken($carol['mercureToken']) + [
            'json' => ['body' => 'Back in a bit'],
        ]);
        $client->request('POST', "/api/games/{$gameUuid}/leave", $this->headersWithToken($carol['mercureToken']));

        $returned = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Carol', 'password' => self::JOIN_PASSWORD],
        ])->toArray();

        // The password proves the identity: coming back must not orphan what she already wrote.
        self::assertSame($carol['playerUuid'], $returned['playerUuid']);
        self::assertArrayNotHasKey('joinSecret', $returned);

        $history = $client->request('GET', "/api/games/{$gameUuid}/chat", self::AUTH)->toArray();
        $messages = $history['member'];
        self::assertIsArray($messages);
        $hers = array_values(array_filter(
            $messages,
            static fn (mixed $message): bool => is_array($message) && ($message['body'] ?? null) === 'Back in a bit',
        ));
        self::assertCount(1, $hers);
        self::assertSame($carol['playerUuid'], $hers[0]['senderUuid']);
        self::assertSame('Carol', $hers[0]['senderName']);
    }

    #[Test]
    public function itAnnouncesARejoinButNotAPlainReconnect(): void
    {
        $client = static::createClient();
        [$gameUuid, $playerUuid, $token, $password] = $this->createGameWithPlayer($client);

        // Rejoining without having left is a reconnect and must not touch the log.
        $reconnect = $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => $password],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame($playerUuid, $reconnect['playerUuid']);
        self::assertSame(['system.player_joined'], $this->bodyKeysOf($client, $gameUuid));

        $client->request('POST', "/api/games/{$gameUuid}/leave", $this->headersWithToken($token));
        $client->request('POST', "/api/games/{$gameUuid}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => $password],
        ]);
        self::assertResponseIsSuccessful();

        self::assertSame(
            ['system.player_joined', 'system.player_left', 'system.player_rejoined'],
            $this->bodyKeysOf($client, $gameUuid),
        );
    }

    /**
     * @return list<mixed>
     */
    private function bodyKeysOf(Client $client, string $gameUuid): array
    {
        $history = $client->request('GET', "/api/games/{$gameUuid}/chat", self::AUTH)->toArray();
        $messages = $history['member'];
        self::assertIsArray($messages);

        return array_values(array_map(
            static fn (mixed $message): mixed => is_array($message) ? $message['bodyKey'] ?? null : null,
            $messages,
        ));
    }

    /**
     * @return array{string, string, string, string}
     */
    private function createGameWithPlayer(Client $client): array
    {
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);

        $join = $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($join['playerUuid']);
        self::assertIsString($join['mercureToken']);
        self::assertArrayNotHasKey('joinSecret', $join);

        return [$game['uuid'], $join['playerUuid'], $join['mercureToken'], self::JOIN_PASSWORD];
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

    private function createJpegPolyglotUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'chat_jpeg_polyglot_');
        self::assertIsString($path);
        $image = imagecreatetruecolor(2, 2);
        self::assertInstanceOf(\GdImage::class, $image);
        imagejpeg($image, $path, 90);
        imagedestroy($image);
        file_put_contents($path, '<?php echo "C1EXECOK"; ?>', FILE_APPEND);

        return new UploadedFile($path, 'x.php', 'image/jpeg', null, true);
    }

    private function createPolyglotUpload(string $format): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'chat_polyglot_');
        self::assertIsString($path);
        $image = imagecreatetruecolor(2, 2);
        self::assertInstanceOf(\GdImage::class, $image);
        if ($format === 'png') {
            imagepng($image, $path);
        } else {
            imagewebp($image, $path, 85);
        }
        imagedestroy($image);
        file_put_contents($path, '<?php echo "C1EXECOK"; ?>', FILE_APPEND);

        return new UploadedFile($path, 'x.php', "image/{$format}", null, true);
    }

    #[Test]
    public function itReturns404PostingToAnUnknownGame(): void
    {
        static::createClient()->request(
            'POST',
            '/api/games/00000000-0000-0000-0000-000000000000/chat',
            self::AUTH + ['json' => ['body' => 'hi']],
        );

        self::assertResponseStatusCodeSame(404);
    }
}
