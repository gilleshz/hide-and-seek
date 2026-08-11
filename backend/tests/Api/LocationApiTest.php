<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Fake\FakeMercureHub;
use PHPUnit\Framework\Attributes\Test;

final class LocationApiTest extends ApiTestCase
{
    #[Test]
    public function itIngestsALocationAndPublishesItPrivatelyToTheHiderTopic(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);
        self::assertIsString($game['roundUuid']);
        $gameUuid = $game['uuid'];
        $roundUuid = $game['roundUuid'];

        [$playerUuid, $token] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Alice', 'hider');

        $location = $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($token) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame($playerUuid, $location['playerUuid']);
        self::assertSame($roundUuid, $location['roundUuid']);
        self::assertFalse($location['endgame']);

        /** @var FakeMercureHub $hub */
        $hub = self::getContainer()->get(FakeMercureHub::class);
        $published = $hub->published();

        self::assertCount(1, $published);
        self::assertSame(["game/{$gameUuid}/round/{$roundUuid}/hider-locations"], $published[0]->getTopics());
        self::assertTrue($published[0]->isPrivate());
        self::assertStringContainsString($playerUuid, $published[0]->getData());
    }

    #[Test]
    public function itRejectsALocationPingBeforeChoosingASide(): void
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
            'json' => ['name' => 'Alice', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($join['playerUuid']);
        self::assertIsString($join['mercureToken']);

        $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($join['mercureToken']) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function itRejectsAPingWithoutASubscriberToken(): void
    {
        $client = static::createClient();
        $game = $this->createGame();

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/location", self::AUTH + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);
    }
}
