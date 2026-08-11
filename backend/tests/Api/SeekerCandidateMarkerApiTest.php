<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use PHPUnit\Framework\Attributes\Test;

final class SeekerCandidateMarkerApiTest extends ApiTestCase
{
    #[Test]
    public function seekersCanMarkListAndUnmarkStations(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);
        $roundUuid = $game['roundUuid'];

        [$seekerUuid, $seekerToken] = $this->joinSeeker($client, $game['uuid'], $roundUuid, 'Alice');

        $markers = "/api/rounds/{$roundUuid}/seeker-candidate-markers";

        $first = $client->request('POST', $markers, $this->headersWithToken($seekerToken) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame(52.52, $first['lat']);
        self::assertSame(13.405, $first['lng']);
        self::assertSame($seekerUuid, $first['playerUuid']);
        self::assertArrayNotHasKey('radiusMeters', $first);
        self::assertIsString($first['uuid']);
        $firstUuid = $first['uuid'];

        $client->request('POST', $markers, $this->headersWithToken($seekerToken) + [
            'json' => ['lat' => 48.85, 'lng' => 2.35],
        ]);
        self::assertResponseIsSuccessful();

        $collection = $client->request('GET', $markers, $this->headersWithToken($seekerToken))->toArray();
        self::assertIsArray($collection['member']);
        self::assertCount(2, $collection['member']);

        $client->request('DELETE', "{$markers}/{$firstUuid}", $this->headersWithToken($seekerToken));
        self::assertResponseStatusCodeSame(204);

        $afterDelete = $client->request('GET', $markers, $this->headersWithToken($seekerToken))->toArray();
        self::assertIsArray($afterDelete['member']);
        self::assertCount(1, $afterDelete['member']);
        $remaining = $afterDelete['member'][0];
        self::assertIsArray($remaining);
        self::assertSame(48.85, $remaining['lat']);
    }

    #[Test]
    public function hidersCannotPlaceCandidateMarkers(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);
        $roundUuid = $game['roundUuid'];

        $session = $this->joinAndPickSide($client, $game['uuid'], $roundUuid, 'Hank', 'hider');

        $client->request('POST', "/api/rounds/{$roundUuid}/seeker-candidate-markers", $this->headersWithToken($session['token']) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function hidersCannotReadCandidateMarkers(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $session = $this->joinAndPickSide($client, $game['uuid'], $game['roundUuid'], 'Hank', 'hider');

        $client->request(
            'GET',
            "/api/rounds/{$game['roundUuid']}/seeker-candidate-markers",
            $this->headersWithToken($session['token']),
        );

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'seeker_candidate.not_seeker']);
    }

    #[Test]
    public function readingCandidateMarkersRequiresASubscriberToken(): void
    {
        $client = static::createClient();
        $game = $this->createGame();

        $client->request('GET', "/api/rounds/{$game['roundUuid']}/seeker-candidate-markers", self::AUTH);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);
    }

    #[Test]
    public function roundStateExposesRadiusOnlyNeverHiderCoordinates(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'L', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];

        $round = $client->request('GET', "/api/rounds/{$roundUuid}", self::AUTH)->toArray();

        self::assertArrayHasKey('hidingRadiusMeters', $round);
        self::assertIsNumeric($round['hidingRadiusMeters']);
        self::assertSame(1000.0, (float) $round['hidingRadiusMeters']);
        self::assertArrayNotHasKey('lat', $round);
        self::assertArrayNotHasKey('lng', $round);
        self::assertArrayNotHasKey('stationPoint', $round);
        self::assertArrayNotHasKey('hidingZone', $round);
    }

    /**
     * @return array{string, string}
     */
    private function joinSeeker(Client $client, string $gameUuid, string $roundUuid, string $name): array
    {
        $session = $this->joinAndPickSide($client, $gameUuid, $roundUuid, $name, 'seeker');

        return [$session['playerUuid'], $session['token']];
    }
}
