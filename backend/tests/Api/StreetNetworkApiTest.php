<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\RoundStreetNetwork;
use App\Enum\StreetNetworkStatus;
use App\Repository\RoundRepository;
use App\Repository\RoundStreetNetworkRepository;
use App\StreetNetworkRules;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\Test;

final class StreetNetworkApiTest extends ApiTestCase
{
    private const float ZONE_LAT = 52.52;

    private const float ZONE_LNG = 13.405;

    private const float ZONE_RADIUS = 500.0;

    private string $hiderToken = '';

    #[Test]
    public function itRefusesAnUnknownRound(): void
    {
        $client = static::createClient();
        $game = $this->game($client);
        $this->joinWithSide($client, $game, 'Alice', 'hider');

        $client->request(
            'GET',
            '/api/rounds/00000000-0000-0000-0000-000000000000/street-network',
            $this->authWith($this->hiderToken),
        );

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function itRefusesEveryCredentialButAHiderSubscriberToken(): void
    {
        $client = static::createClient();
        $game = $this->game($client);
        $this->joinWithSide($client, $game, 'Alice', 'hider');
        $seekerToken = $this->joinWithSide($client, $game, 'Bob', 'seeker');

        $client->request('GET', $this->url($game['roundUuid']), $this->authWith($seekerToken));
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['detail' => 'Only a hider on this round may read that.']);

        $client->request('GET', $this->url($game['roundUuid']), self::AUTH);
        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);

        $client->request('GET', $this->url($game['roundUuid']), $this->authWith('not-a-token'));
        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_invalid']);
    }

    #[Test]
    public function itReportsPendingAndCreatesNoRowBeforeAZoneIsPlaced(): void
    {
        $client = static::createClient();
        $game = $this->game($client);
        $this->joinWithSide($client, $game, 'Alice', 'hider');

        $body = $client->request('GET', $this->url($game['roundUuid']), $this->authWith($this->hiderToken))->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame($game['roundUuid'], $body['roundUuid']);
        self::assertSame('pending', $body['status']);
        self::assertSame(0, $body['wayCount']);
        self::assertSame([], $body['ways']);
        self::assertArrayNotHasKey('fetchedAt', $body);

        /** @var RoundStreetNetworkRepository $networks */
        $networks = self::getContainer()->get(RoundStreetNetworkRepository::class);
        self::assertNull($networks->findOneByRound($this->storedRound($game['roundUuid'])));
    }

    /** The zone placement is the enqueue choke point, so a hider who reads before the warm sees pending. */
    #[Test]
    public function itEnqueuesAPendingRowWhenTheHiderPlacesTheZoneDuringTheHidingPeriod(): void
    {
        $client = static::createClient();
        $game = $this->game($client);
        $this->joinWithSide($client, $game, 'Alice', 'hider');
        $client->request('POST', "/api/rounds/{$game['roundUuid']}/start", $this->authWith($this->hiderToken) + ['json' => []]);
        $this->placeZone($client, $game['roundUuid']);

        $body = $client->request('GET', $this->url($game['roundUuid']), $this->authWith($this->hiderToken))->toArray();

        self::assertSame('pending', $body['status']);
        self::assertSame([], $body['ways']);

        $round = $this->storedRound($game['roundUuid']);
        /** @var RoundStreetNetworkRepository $networks */
        $networks = self::getContainer()->get(RoundStreetNetworkRepository::class);
        $enqueued = $networks->findOneByRound($round);
        self::assertNotNull($enqueued);
        self::assertSame(StreetNetworkStatus::Pending, $enqueued->getStatus());
        self::assertSame(self::ZONE_RADIUS, $enqueued->getRadiusMeters());
        self::assertSame(self::ZONE_LNG, $enqueued->getCenter()->getLongitude());
        self::assertSame(self::ZONE_LAT, $enqueued->getCenter()->getLatitude());
    }

    #[Test]
    public function itHandsAWarmedNetworkToTheHiderWithItsWaysAndJunctions(): void
    {
        $client = static::createClient();
        $game = $this->game($client);
        $this->joinWithSide($client, $game, 'Alice', 'hider');
        $this->placeZone($client, $game['roundUuid']);
        $fetchedAt = $this->storeWarmedNetwork($game['roundUuid']);

        $body = $client->request('GET', $this->url($game['roundUuid']), $this->authWith($this->hiderToken))->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame($game['roundUuid'], $body['roundUuid']);
        self::assertSame('ready', $body['status']);
        self::assertSame($fetchedAt->format(\DateTimeInterface::ATOM), $body['fetchedAt']);
        self::assertArrayNotHasKey('centerLatitude', $body);
        self::assertArrayNotHasKey('centerLongitude', $body);
        self::assertArrayNotHasKey('radiusMeters', $body);
        self::assertSame(2, $body['wayCount']);
        self::assertSame(
            [
                [
                    'class' => 'residential',
                    'coordinates' => [[13.40512, 52.52001], [13.40598, 52.52044]],
                    'junctionIndices' => [0],
                ],
                [
                    'class' => 'sidewalk',
                    'coordinates' => [[13.40512, 52.52001], [13.40512, 52.52099]],
                    'junctionIndices' => [0],
                ],
            ],
            $body['ways'],
        );
    }

    /**
     * The only way a row reaches `unavailable` is exhausting its attempts, which leaves it with no geometry
     * at all, so the hider traces freehand for the rest of the round.
     */
    #[Test]
    public function itServesAnExhaustedNetworkAsUnavailableWithNothingToSnapTo(): void
    {
        $client = static::createClient();
        $game = $this->game($client);
        $this->joinWithSide($client, $game, 'Alice', 'hider');
        $this->placeZone($client, $game['roundUuid']);
        $this->storeExhaustedNetwork($game['roundUuid']);

        $body = $client->request('GET', $this->url($game['roundUuid']), $this->authWith($this->hiderToken))->toArray();

        self::assertSame('unavailable', $body['status']);
        self::assertSame([], $body['ways']);
        self::assertSame(0, $body['wayCount']);
        self::assertArrayNotHasKey('fetchedAt', $body);
    }

    private function url(string $roundUuid): string
    {
        return "/api/rounds/{$roundUuid}/street-network";
    }

    private function storedRound(string $roundUuid): \App\Entity\Round
    {
        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);

        return $round;
    }

    private function storeExhaustedNetwork(string $roundUuid): void
    {
        $network = $this->newNetwork($roundUuid)
            ->setAttempts(StreetNetworkRules::MAX_WARM_ATTEMPTS)
            ->setStatus(StreetNetworkStatus::Unavailable);

        /** @var RoundStreetNetworkRepository $networks */
        $networks = self::getContainer()->get(RoundStreetNetworkRepository::class);
        $networks->save($network);
    }

    private function newNetwork(string $roundUuid): RoundStreetNetwork
    {
        return new RoundStreetNetwork(
            $this->storedRound($roundUuid),
            new Point(self::ZONE_LNG, self::ZONE_LAT),
            self::ZONE_RADIUS,
        );
    }

    private function storeWarmedNetwork(string $roundUuid): \DateTimeImmutable
    {
        $fetchedAt = new \DateTimeImmutable('2026-08-01 12:00:00');
        $network = $this->newNetwork($roundUuid);
        $network
            ->setPayload([
                [
                    'class' => 'residential',
                    'coordinates' => [[13.40512, 52.52001], [13.40598, 52.52044]],
                    'junctionIndices' => [0],
                ],
                [
                    'class' => 'sidewalk',
                    'coordinates' => [[13.40512, 52.52001], [13.40512, 52.52099]],
                    'junctionIndices' => [0],
                ],
            ])
            ->setWayCount(2)
            ->setFetchedAt($fetchedAt)
            ->setStatus(StreetNetworkStatus::Ready);

        /** @var RoundStreetNetworkRepository $networks */
        $networks = self::getContainer()->get(RoundStreetNetworkRepository::class);
        $networks->save($network);

        return $fetchedAt;
    }

    private function placeZone(Client $client, string $roundUuid): void
    {
        $client->request('POST', "/api/rounds/{$roundUuid}/zone", $this->authWith($this->hiderToken) + [
            'json' => [
                'lat' => self::ZONE_LAT,
                'lng' => self::ZONE_LNG,
                'radiusMeters' => self::ZONE_RADIUS,
                'stationName' => 'Alexanderplatz',
            ],
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array{roundUuid: string, gameUuid: string}
     */
    private function game(Client $client): array
    {
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);

        return ['roundUuid' => $game['roundUuid'], 'gameUuid' => $game['uuid']];
    }

    /**
     * @param array{roundUuid: string, gameUuid: string} $game
     */
    private function joinWithSide(Client $client, array $game, string $name, string $side): string
    {
        $session = $this->joinAndPickSide($client, $game['gameUuid'], $game['roundUuid'], $name, $side);
        if ($side === 'hider') {
            $this->hiderToken = $session['token'];
        }

        return $session['token'];
    }
}
