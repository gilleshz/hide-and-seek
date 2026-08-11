<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\GameTransitStation;
use App\Enum\RoundStatus;
use App\Repository\GameRepository;
use App\Repository\GameTransitStationRepository;
use App\Repository\RoundRepository;
use App\TimeTrapRules;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TimeTrapApiTest extends ApiTestCase
{
    private const float STATION_LAT = 52.52;

    private const float STATION_LNG = 13.405;

    #[Test]
    public function itSnapsAPlacementToTheStationAndListsItForBothSides(): void
    {
        $client = static::createClient();
        $round = $this->huntedRound($client);

        $placed = $this->placeTrap($client, $round, $round['hiderToken'], 52.5211, 13.4062)->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('Alexanderplatz', $placed['stationName']);
        self::assertSame(self::STATION_LAT, $placed['lat']);
        self::assertSame(self::STATION_LNG, $placed['lng']);
        self::assertSame('armed', $placed['status']);
        self::assertSame(0, $placed['valueSeconds']);
        self::assertSame(30, $placed['intervalMinutes']);
        self::assertSame(6, $placed['incrementMinutes']);
        self::assertNull($placed['detectedAt']);
        self::assertNull($placed['detectedByName']);
        self::assertNull($placed['awardedSeconds']);
        self::assertSame($round['roundUuid'], $placed['roundUuid']);

        $collection = $client->request('GET', $this->trapsUrl($round), self::AUTH)->toArray();
        self::assertIsArray($collection['member']);
        self::assertCount(1, $collection['member']);

        $chat = $client->request('GET', "/api/games/{$round['gameUuid']}/chat", self::AUTH)->toArray();
        $messages = $chat['member'];
        self::assertIsArray($messages);
        $last = end($messages);
        self::assertIsArray($last);
        self::assertSame('trap.placed', $last['bodyKey']);
        // bodyArgs lands in a jsonb column, which does not preserve key order; the client reads by name.
        self::assertEquals(
            ['station' => 'Alexanderplatz', 'increment' => 6, 'interval' => 30],
            $last['bodyArgs'],
        );
        self::assertNotNull($last['imageRef']);
    }

    #[Test]
    public function itRefusesAPlacementWithNoStationInReachAndASeekerPlacing(): void
    {
        $client = static::createClient();
        $round = $this->huntedRound($client);

        $this->placeTrap($client, $round, $round['hiderToken'], 52.60, 13.60);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'A time trap has to be placed on a transit station.']);

        $this->placeTrap($client, $round, $round['seekerToken'], self::STATION_LAT, self::STATION_LNG);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'Only a hider may place a time trap.']);
    }

    #[Test]
    public function itStopsAcceptingTrapsOnceTheRoundHoldsTheCap(): void
    {
        $client = static::createClient();
        $round = $this->huntedRound($client);

        for ($i = 0; $i < TimeTrapRules::MAX_TRAPS_PER_ROUND; ++$i) {
            $this->placeTrap($client, $round, $round['hiderToken'], self::STATION_LAT, self::STATION_LNG);
            self::assertResponseIsSuccessful();
        }

        $this->placeTrap($client, $round, $round['hiderToken'], self::STATION_LAT, self::STATION_LNG);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'This round already holds the maximum number of time traps.']);
    }

    /**
     * The pings straddle the station 470 m either side, so neither is inside the 50 m trip radius;
     * only the segment between them catches the pass.
     */
    #[Test]
    public function itCatchesAFlyByThatAPointTestWouldMissThenGoesQuietAfterADismissal(): void
    {
        $client = static::createClient();
        $round = $this->huntedRound($client);
        $this->placeTrap($client, $round, $round['hiderToken'], self::STATION_LAT, self::STATION_LNG);
        self::assertResponseIsSuccessful();

        $this->ping($client, $round, 13.3980);
        self::assertSame('armed', $this->onlyTrap($client, $round)['status']);

        $this->ping($client, $round, 13.4120);
        $detected = $this->onlyTrap($client, $round);
        self::assertSame('pending', $detected['status']);
        self::assertSame('Bob', $detected['detectedByName']);
        self::assertNotNull($detected['detectedAt']);
        self::assertIsString($detected['uuid']);
        $trapUuid = $detected['uuid'];

        $chat = $client->request('GET', "/api/games/{$round['gameUuid']}/chat", self::AUTH)->toArray();
        $messages = $chat['member'];
        self::assertIsArray($messages);
        $last = end($messages);
        self::assertIsArray($last);
        self::assertSame('trap.detected', $last['bodyKey']);
        self::assertIsArray($last['bodyArgs']);
        self::assertSame('Alexanderplatz', $last['bodyArgs']['station']);
        self::assertSame('Bob', $last['bodyArgs']['seeker']);
        self::assertSame(0, $last['bodyArgs']['minutes']);
        self::assertArrayHasKey('speed', $last['bodyArgs']);

        $dismissed = $this->resolve($client, $round, $trapUuid, $round['seekerToken'], false)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('armed', $dismissed['status']);
        self::assertNull($dismissed['detectedByName']);
        self::assertNull($dismissed['awardedSeconds']);

        $this->ping($client, $round, 13.3980);
        $this->ping($client, $round, 13.4120);
        self::assertSame('armed', $this->onlyTrap($client, $round)['status']);
    }

    #[Test]
    public function itSpringsAConfirmedTrapAndRefusesToResolveItTwice(): void
    {
        $client = static::createClient();
        $round = $this->huntedRound($client);
        $this->placeTrap($client, $round, $round['hiderToken'], self::STATION_LAT, self::STATION_LNG);

        $this->ping($client, $round, 13.3980);
        $this->ping($client, $round, 13.4120);
        $detected = $this->onlyTrap($client, $round);
        self::assertSame('pending', $detected['status']);
        self::assertIsString($detected['uuid']);
        $trapUuid = $detected['uuid'];

        $this->resolve($client, $round, $trapUuid, $round['hiderToken'], true);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'Only a seeker may resolve a time trap.']);

        $sprung = $this->resolve($client, $round, $trapUuid, $round['seekerToken'], true)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('sprung', $sprung['status']);
        self::assertSame(0, $sprung['awardedSeconds']);

        $chat = $client->request('GET', "/api/games/{$round['gameUuid']}/chat", self::AUTH)->toArray();
        $messages = $chat['member'];
        self::assertIsArray($messages);
        $last = end($messages);
        self::assertIsArray($last);
        self::assertSame('trap.sprung', $last['bodyKey']);
        self::assertEquals(['station' => 'Alexanderplatz', 'minutes' => 0], $last['bodyArgs']);

        $this->resolve($client, $round, $trapUuid, $round['seekerToken'], false);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'This time trap is not awaiting a resolution.']);

        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $stored = $rounds->findOneByUuid($round['roundUuid']);
        self::assertNotNull($stored);
        self::assertSame(0, $stored->getTrapBonusSeconds());
    }

    /** Once the round has stopped, its score is announced and ranked, so a detection cannot move it. */
    #[Test]
    public function itRefusesToResolveADetectionAfterTheRoundHasEnded(): void
    {
        $client = static::createClient();
        $round = $this->huntedRound($client);
        $this->placeTrap($client, $round, $round['hiderToken'], self::STATION_LAT, self::STATION_LNG);

        $this->ping($client, $round, 13.3980);
        $this->ping($client, $round, 13.4120);
        $detected = $this->onlyTrap($client, $round);
        self::assertSame('pending', $detected['status']);
        self::assertIsString($detected['uuid']);

        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $stored = $rounds->findOneByUuid($round['roundUuid']);
        self::assertNotNull($stored);
        $stored->setStatus(RoundStatus::Ended)->setSeekingEndedAt(new \DateTimeImmutable());
        $rounds->save($stored);

        $this->resolve($client, $round, $detected['uuid'], $round['seekerToken'], true);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'Time traps can only be resolved while the seekers are hunting.']);

        self::assertSame('pending', $this->onlyTrap($client, $round)['status']);
        $after = $rounds->findOneByUuid($round['roundUuid']);
        self::assertNotNull($after);
        self::assertSame(0, $after->getTrapBonusSeconds());
    }

    #[Test]
    public function itRefusesAResolutionForAnUnknownTrap(): void
    {
        $client = static::createClient();
        $round = $this->huntedRound($client);

        $this->resolve($client, $round, '00000000-0000-0000-0000-000000000000', $round['seekerToken'], true);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array{roundUuid: string, gameUuid: string, hiderToken: string, seekerToken: string} $round
     *
     * @return array<array-key, mixed>
     */
    private function onlyTrap(Client $client, array $round): array
    {
        $collection = $client->request('GET', $this->trapsUrl($round), self::AUTH)->toArray();
        self::assertIsArray($collection['member']);
        self::assertCount(1, $collection['member']);
        $trap = $collection['member'][0];
        self::assertIsArray($trap);

        return $trap;
    }

    /**
     * @param array{roundUuid: string, gameUuid: string, hiderToken: string, seekerToken: string} $round
     */
    private function trapsUrl(array $round): string
    {
        return "/api/rounds/{$round['roundUuid']}/time-traps";
    }

    /**
     * recordedAt has second precision and the segment window needs a positive gap, so consecutive
     * pings wait a real second rather than landing in the same one.
     *
     * @param array{roundUuid: string, gameUuid: string, hiderToken: string, seekerToken: string} $round
     */
    private function ping(Client $client, array $round, float $lng): void
    {
        sleep(1);

        $client->request('POST', "/api/rounds/{$round['roundUuid']}/location", $this->headersWithToken($round['seekerToken']) + [
            'json' => ['lat' => self::STATION_LAT, 'lng' => $lng],
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array{roundUuid: string, gameUuid: string, hiderToken: string, seekerToken: string} $round
     */
    private function resolve(
        Client $client,
        array $round,
        string $trapUuid,
        string $token,
        bool $confirmed,
    ): ResponseInterface {
        return $client->request('POST', "{$this->trapsUrl($round)}/{$trapUuid}/resolution", $this->headersWithToken($token) + [
            'json' => ['confirmed' => $confirmed],
        ]);
    }

    /**
     * @param array{roundUuid: string, gameUuid: string, hiderToken: string, seekerToken: string} $round
     */
    private function placeTrap(
        Client $client,
        array $round,
        string $token,
        float $lat,
        float $lng,
    ): ResponseInterface {
        return $client->request('POST', $this->trapsUrl($round), [
            'headers' => ['Content-Type' => 'multipart/form-data'] + $this->headersWithToken($token)['headers'],
            'extra' => [
                'parameters' => [
                    'lat' => (string) $lat,
                    'lng' => (string) $lng,
                ],
                'files' => ['image' => $this->cardPhoto()],
            ],
        ]);
    }

    private function cardPhoto(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'trap_card_');
        self::assertIsString($path);
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertIsString($png);
        file_put_contents($path, $png);

        return new UploadedFile($path, 'card.png', 'image/png', null, true);
    }

    /**
     * @return array{roundUuid: string, gameUuid: string, hiderUuid: string, hiderToken: string, seekerUuid: string, seekerToken: string}
     */
    private function huntedRound(Client $client): array
    {
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);
        $roundUuid = $game['roundUuid'];
        $gameUuid = $game['uuid'];

        $hider = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Alice', 'hider');
        $seeker = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Bob', 'seeker');
        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($hider['token']) + ['json' => []]);

        /** @var GameRepository $games */
        $games = self::getContainer()->get(GameRepository::class);
        $storedGame = $games->findOneByUuid($gameUuid);
        self::assertNotNull($storedGame);

        /** @var GameTransitStationRepository $stations */
        $stations = self::getContainer()->get(GameTransitStationRepository::class);
        $stations->save(new GameTransitStation(
            $storedGame,
            'de:11000:900100003',
            'Alexanderplatz',
            new Point(self::STATION_LNG, self::STATION_LAT),
            ['U2'],
        ));

        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);
        $round->setStatus(RoundStatus::Seeking)->setHidingPeriodEndsAt(new \DateTimeImmutable('-15 minutes'));
        $rounds->save($round);

        return [
            'roundUuid' => $roundUuid,
            'gameUuid' => $gameUuid,
            'hiderUuid' => $hider['playerUuid'],
            'hiderToken' => $hider['token'],
            'seekerUuid' => $seeker['playerUuid'],
            'seekerToken' => $seeker['token'],
        ];
    }
}
