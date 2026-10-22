<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\Client;
use ApiPlatform\Test\Response;
use App\Repository\IdentityRepository;
use App\Service\ProfileIdentityResolver;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProfileApiTest extends ApiTestCase
{
    #[Test]
    public function itUploadsAnAvatarAndServesItToTheProfileAndTheRoster(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $this->joinGame($game, 'Alice');

        $uploaded = $this->uploadAvatar($client, 'Alice', self::DEVICE_KEY)->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Alice', $uploaded['name']);
        self::assertIsString($uploaded['avatarRef']);
        self::assertStringEndsWith('.jpg', $uploaded['avatarRef']);

        $profile = $client->request('GET', '/api/profile', $this->profileHeaders('Alice'))->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('Alice', $profile['name']);
        self::assertSame($uploaded['avatarRef'], $profile['avatarRef']);

        $client->request('GET', "/api/avatars/{$uploaded['avatarRef']}", self::AUTH);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'image/jpeg');
        $response = $client->getResponse();
        self::assertNotNull($response);
        self::assertStringStartsWith("\xFF\xD8", (string) $response->getContent());

        $roster = $client->request('GET', "/api/games/{$game['uuid']}/players", self::AUTH)->toArray();
        $members = $roster['member'];
        self::assertIsArray($members);
        self::assertIsArray($members[0]);
        self::assertSame($uploaded['avatarRef'], $members[0]['avatarRef']);
    }

    #[Test]
    public function itReturns404ForAnUnknownAvatarRef(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/avatars/does-not-exist.jpg', self::AUTH);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function itRejectsAnUploadWithTheWrongDeviceKey(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $this->joinGame($game, 'Alice');

        $this->uploadAvatar($client, 'Alice', self::OTHER_DEVICE_KEY);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'profile.credential_invalid']);
    }

    #[Test]
    public function itDeletesTheAvatarAndClearsItFromTheProfile(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $this->joinGame($game, 'Alice');

        $uploaded = $this->uploadAvatar($client, 'Alice', self::DEVICE_KEY)->toArray();
        self::assertIsString($uploaded['avatarRef']);

        $client->request('DELETE', '/api/profile/avatar', $this->profileHeaders('Alice'));

        self::assertResponseStatusCodeSame(204);
        $profile = $client->request('GET', '/api/profile', $this->profileHeaders('Alice'))->toArray();
        self::assertArrayNotHasKey('avatarRef', $profile);

        $identities = self::getContainer()->get(IdentityRepository::class);
        self::assertInstanceOf(IdentityRepository::class, $identities);
        $identity = $identities->findByName('Alice');
        self::assertNotNull($identity);
        self::assertNull($identity->getAvatarRef());
    }

    private function uploadAvatar(Client $client, string $name, string $deviceKey): Response
    {
        $response = $client->request('POST', '/api/profile/avatar', [
            'headers' => self::AUTH['headers'] + [
                'Content-Type' => 'multipart/form-data',
                ProfileIdentityResolver::HEADER => $deviceKey,
            ],
            'query' => ['name' => $name],
            'extra' => [
                'parameters' => [],
                'files' => ['image' => $this->createJpegUpload()],
            ],
        ]);
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * @return array{query: array{name: string}, headers: array<string, string>}
     */
    private function profileHeaders(string $name): array
    {
        return [
            'query' => ['name' => $name],
            'headers' => self::AUTH['headers'] + [ProfileIdentityResolver::HEADER => self::DEVICE_KEY],
        ];
    }

    private function createJpegUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'profile_jpeg_');
        self::assertIsString($path);
        $image = imagecreatetruecolor(100, 50);
        self::assertInstanceOf(\GdImage::class, $image);
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return new UploadedFile($path, 'avatar.jpg', 'image/jpeg', null, true);
    }
}
