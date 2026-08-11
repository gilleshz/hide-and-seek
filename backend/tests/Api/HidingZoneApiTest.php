<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Enum\RoundStatus;
use App\Repository\RoundRepository;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HidingZoneApiTest extends ApiTestCase
{
    #[Test]
    public function itLetsAHiderSetTheZoneWithTheDefaultRadiusAndAdjustItAfterwards(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        self::assertIsString($game['uuid']);
        $gameUuid = $game['uuid'];

        $session = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Alice', 'hider');
        $token = $session['token'];

        $zone = $client->request('POST', "/api/rounds/{$roundUuid}/zone", $this->headersWithToken($token) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame($roundUuid, $zone['roundUuid']);
        self::assertSame(52.52, $zone['lat']);
        self::assertSame(13.405, $zone['lng']);
        self::assertEquals(500.0, $zone['radiusMeters']);

        $adjusted = $client->request('POST', "/api/rounds/{$roundUuid}/zone", $this->headersWithToken($token) + [
            'json' => ['lat' => 48.85, 'lng' => 2.35, 'radiusMeters' => 750.0],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(48.85, $adjusted['lat']);
        self::assertSame(2.35, $adjusted['lng']);
        self::assertEquals(750.0, $adjusted['radiusMeters']);
    }

    #[Test]
    public function itRejectsASeekerSettingTheZone(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        self::assertIsString($game['uuid']);
        $gameUuid = $game['uuid'];

        $session = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Bob', 'seeker');
        $token = $session['token'];

        $client->request('POST', "/api/rounds/{$roundUuid}/zone", $this->headersWithToken($token) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function itPlaysProsperousHomeAndThenMoveWithAPhotoOfTheCard(): void
    {
        $client = static::createClient();
        [$roundUuid, $playerUuid, $gameUuid, $token] = $this->huntedRoundWithAZone($client);

        $expanded = $this->playCard($client, $roundUuid, $token, 'prosperous_home')->toArray();

        self::assertResponseIsSuccessful();
        self::assertEquals(750.0, $expanded['radiusMeters']);

        $this->playCard($client, $roundUuid, $token, 'move');

        self::assertResponseIsSuccessful();
        $chat = $client->request('GET', "/api/games/{$gameUuid}/chat", self::AUTH)->toArray();
        $messages = $chat['member'];
        self::assertIsArray($messages);
        $last = end($messages);
        self::assertIsArray($last);
        self::assertSame('zone.move_played_from', $last['bodyKey']);
        self::assertIsArray($last['bodyArgs']);
        self::assertSame('Alexanderplatz', $last['bodyArgs']['station']);

        $round = $client->request('GET', "/api/rounds/{$roundUuid}", self::AUTH)->toArray();
        self::assertSame('hiding', $round['status']);
        self::assertTrue($round['inMovePeriod']);
        self::assertGreaterThan(0, $round['bankedSeekingSeconds']);
    }

    #[Test]
    public function itRejectsAnUnknownCardAndACardPlayedWhileHidingIsStillRunning(): void
    {
        $client = static::createClient();
        [$roundUuid, , , $token] = $this->huntedRoundWithAZone($client);

        $this->playCard($client, $roundUuid, $token, 'curse_of_the_bogus_home');
        self::assertResponseStatusCodeSame(400);

        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);
        $round->setStatus(RoundStatus::Hiding)->setHidingPeriodEndsAt(new \DateTimeImmutable('+10 minutes'));
        $rounds->save($round);

        $this->playCard($client, $roundUuid, $token, 'tiny_home');
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'Zone cards only come into play once the seekers are hunting.']);
    }

    #[Test]
    public function itHandsTheZoneBackToAHiderHoldingTheSubscriberTokenAndToNobodyElse(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);
        $roundUuid = $game['roundUuid'];

        $hider = $this->joinAndPickSide($client, $game['uuid'], $roundUuid, 'Alice', 'hider');
        $hiderToken = $hider['token'];
        $seeker = $this->joinAndPickSide($client, $game['uuid'], $roundUuid, 'Bob', 'seeker');
        $seekerToken = $seeker['token'];

        $client->request('GET', "/api/rounds/{$roundUuid}/zone", $this->authWith($hiderToken));
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/api/rounds/{$roundUuid}/zone", $this->authWith($hiderToken) + [
            'json' => [
                'lat' => 52.52,
                'lng' => 13.405,
                'radiusMeters' => 500.0,
                'stationName' => 'Alexanderplatz',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $zone = $client->request('GET', "/api/rounds/{$roundUuid}/zone", $this->authWith($hiderToken))->toArray();
        self::assertSame(52.52, $zone['lat']);
        self::assertSame(13.405, $zone['lng']);
        self::assertEquals(500.0, $zone['radiusMeters']);
        self::assertSame('Alexanderplatz', $zone['stationName']);

        $client->request('GET', "/api/rounds/{$roundUuid}/zone", $this->authWith($seekerToken));
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'Only a hider on this round may read that.']);

        $client->request('GET', "/api/rounds/{$roundUuid}/zone", self::AUTH);
        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);

        $client->request('GET', "/api/rounds/{$roundUuid}/zone", $this->authWith('not-a-token'));
        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_invalid']);
    }

    #[Test]
    public function itRefusesToNudgeAnExistingZoneOnceTheSeekersAreHunting(): void
    {
        $client = static::createClient();
        [$roundUuid, , , $token] = $this->huntedRoundWithAZone($client);

        $client->request('POST', "/api/rounds/{$roundUuid}/zone", $this->authWith($token) + [
            'json' => ['lat' => 48.85, 'lng' => 2.35, 'radiusMeters' => 900.0],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains([
            'detail' => 'Once the seekers are hunting, the hiding zone only changes by playing a card.',
        ]);

        $round = $client->request('GET', "/api/rounds/{$roundUuid}", self::AUTH)->toArray();
        self::assertEquals(500.0, $round['hidingRadiusMeters']);
    }

    #[Test]
    public function itStillLetsHidersPlaceAFirstZoneAfterTheHidingPeriodRanOut(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);
        $roundUuid = $game['roundUuid'];

        $session = $this->joinAndPickSide($client, $game['uuid'], $roundUuid, 'Alice', 'hider');
        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->authWith($session['token']) + ['json' => []]);

        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);
        $round->setStatus(RoundStatus::Seeking)->setHidingPeriodEndsAt(new \DateTimeImmutable('-15 minutes'));
        $rounds->save($round);

        $before = $client->request('GET', "/api/rounds/{$roundUuid}", self::AUTH)->toArray();
        self::assertFalse($before['hasHidingZone']);

        $client->request('POST', "/api/rounds/{$roundUuid}/zone", $this->authWith($session['token']) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);

        self::assertResponseIsSuccessful();
        $after = $client->request('GET', "/api/rounds/{$roundUuid}", self::AUTH)->toArray();
        self::assertTrue($after['hasHidingZone']);
    }

    /**
     * A hider with a zone in a round whose hiding period has run out, which is when cards come into play.
     *
     * @return array{string, string, string, string}
     */
    private function huntedRoundWithAZone(Client $client): array
    {
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);
        $roundUuid = $game['roundUuid'];

        $session = $this->joinAndPickSide($client, $game['uuid'], $roundUuid, 'Alice', 'hider');
        $token = $session['token'];
        $playerUuid = $session['playerUuid'];

        $client->request('POST', "/api/rounds/{$roundUuid}/zone", $this->authWith($token) + [
            'json' => [
                'lat' => 52.52,
                'lng' => 13.405,
                'radiusMeters' => 500.0,
                'stationName' => 'Alexanderplatz',
            ],
        ]);
        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->authWith($token) + ['json' => []]);

        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);
        $round->setStatus(RoundStatus::Seeking)->setHidingPeriodEndsAt(new \DateTimeImmutable('-15 minutes'));
        $rounds->save($round);

        return [$roundUuid, $playerUuid, $game['uuid'], $token];
    }

    private function playCard(Client $client, string $roundUuid, string $token, string $card): ResponseInterface
    {
        return $client->request('POST', "/api/rounds/{$roundUuid}/zone/card", [
            'headers' => ['Content-Type' => 'multipart/form-data'] + $this->authWith($token)['headers'],
            'extra' => [
                'parameters' => ['card' => $card],
                'files' => ['image' => $this->cardPhoto()],
            ],
        ]);
    }

    private function cardPhoto(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zone_card_');
        self::assertIsString($path);
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertIsString($png);
        file_put_contents($path, $png);

        return new UploadedFile($path, 'card.png', 'image/png', null, true);
    }
}
