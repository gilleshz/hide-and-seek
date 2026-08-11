<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use PHPUnit\Framework\Attributes\Test;

final class QuestionPreviewApiTest extends ApiTestCase
{
    /**
     * @return array{roundUuid: string, gameUuid: string, playerUuid: string, token: string}
     */
    private function createGameAndJoinAs(Client $client, string $name, string $side): array
    {
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => $name, 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);

        $join = $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
            'json' => ['name' => $name, 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($join['playerUuid']);
        self::assertIsString($join['mercureToken']);
        $token = $join['mercureToken'];

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/team", $this->headersWithToken($token) + [
            'json' => ['side' => $side],
        ]);
        self::assertResponseIsSuccessful();

        return [
            'roundUuid' => $game['roundUuid'],
            'gameUuid' => $game['uuid'],
            'playerUuid' => $join['playerUuid'],
            'token' => $token,
        ];
    }

    private function previewUrl(string $roundUuid): string
    {
        return "/api/rounds/{$roundUuid}/question-preview";
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array{current: float, projected: float}
     */
    private function extractAreas(array $data): array
    {
        self::assertIsFloat($data['currentAreaKm2']);
        self::assertIsFloat($data['projectedAreaKm2']);

        return ['current' => $data['currentAreaKm2'], 'projected' => $data['projectedAreaKm2']];
    }

    #[Test]
    public function previewWithZeroConstraintsReturnsEnvelopeFallback(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'PreviewTest', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'radar',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'radiusMeters' => 1000,
                'hypotheticalAnswer' => 'inside',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $areas = $this->extractAreas($response->toArray());
        self::assertGreaterThan(0.0, $areas['current']);
        self::assertGreaterThan(0.0, $areas['projected']);
        self::assertLessThanOrEqual($areas['current'], $areas['projected'] * 1.01 + 0.001);
    }

    #[Test]
    public function previewWithOutsideAnswerReturnsComplementLargerThanDisk(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'OutsideTest', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'radar',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'radiusMeters' => 1000,
                'hypotheticalAnswer' => 'outside',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $areas = $this->extractAreas($response->toArray());
        self::assertGreaterThan(0.0, $areas['current']);
        self::assertGreaterThan($areas['current'] * 0.9, $areas['projected']);
    }

    #[Test]
    public function rejectsNonSeeker(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'HiderTest', 'hider');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'radar',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'radiusMeters' => 1000,
                'hypotheticalAnswer' => 'inside',
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function missingRoundReturns404(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/rounds/00000000-0000-0000-0000-000000000000/question-preview', self::AUTH + [
            'json' => [
                'askerPlayerUuid' => '00000000-0000-0000-0000-000000000000',
                'category' => 'radar',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'radiusMeters' => 1000,
                'hypotheticalAnswer' => 'inside',
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function previewWithoutATokenReturns401(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'NoTokenTest', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), self::AUTH + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'radar',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'radiusMeters' => 1000,
                'hypotheticalAnswer' => 'inside',
            ],
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);
    }

    #[Test]
    public function thermometerPreviewReturnsValidAreas(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'ThermoTest', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'thermometer',
                'seekerLat' => 52.52,
                'seekerLng' => 13.38,
                'endLat' => 52.53,
                'endLng' => 13.43,
                'hypotheticalAnswer' => 'hotter',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $data = $response->toArray();
        $areas = $this->extractAreas($data);
        self::assertGreaterThan(0.0, $areas['current']);
        self::assertGreaterThan(0.0, $areas['projected']);
        self::assertIsString($data['currentPossibleAreaGeoJson']);
        self::assertIsString($data['projectedPossibleAreaGeoJson']);
    }

    #[Test]
    public function radarPreviewReturnsConstraintGeoJson(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'CGeoTest', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'radar',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'radiusMeters' => 1000,
                'hypotheticalAnswer' => 'inside',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $data = $response->toArray();
        self::assertIsString($data['constraintGeoJson']);
        self::assertNotEmpty($data['constraintGeoJson']);
    }

    #[Test]
    public function thermometerPreviewReturnsConstraintGeoJson(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'TCGeoTest', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'thermometer',
                'seekerLat' => 52.52,
                'seekerLng' => 13.38,
                'endLat' => 52.53,
                'endLng' => 13.43,
                'hypotheticalAnswer' => 'hotter',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $data = $response->toArray();
        self::assertIsString($data['constraintGeoJson']);
        self::assertNotEmpty($data['constraintGeoJson']);
    }

    #[Test]
    public function measuringWithoutFeatureTypeReturnsError(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'MeasNoFt', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'measuring',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'hypotheticalAnswer' => 'closer',
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function measuringWithCoastlineReturnsError(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'MeasCoast', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'measuring',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'featureType' => 'coastline',
                'hypotheticalAnswer' => 'closer',
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function measuringReturnsValidResponse(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'MeasTest', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'measuring',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'featureType' => 'museum',
                'hypotheticalAnswer' => 'closer',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $data = $response->toArray();
        $areas = $this->extractAreas($data);
        self::assertGreaterThan(0.0, $areas['current']);
        self::assertGreaterThan(0.0, $areas['projected']);
    }

    #[Test]
    public function matchingWithoutFeatureTypeReturnsError(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'MatchNoFt', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'matching',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'hypotheticalAnswer' => 'same',
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function matchingWithoutChosenFeatureReturnsError(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'MatchNoFeat', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'matching',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'featureType' => 'museum',
                'hypotheticalAnswer' => 'same',
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function matchingReturnsValidResponse(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'MatchOk', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'matching',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'featureType' => 'museum',
                'hypotheticalFeatureId' => '00000000-0000-0000-0000-000000000000',
                'hypotheticalAnswer' => 'same',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $data = $response->toArray();
        $areas = $this->extractAreas($data);
        self::assertGreaterThan(0.0, $areas['current']);
        self::assertGreaterThan(0.0, $areas['projected']);
    }

    #[Test]
    public function matchingDifferentReturnsConstraintGeoJson(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'MatchDiff', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'matching',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'featureType' => 'museum',
                'hypotheticalFeatureId' => '00000000-0000-0000-0000-000000000000',
                'hypotheticalAnswer' => 'different',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    #[Test]
    public function tentaclesWithoutRangeReturnsError(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'TentNoRng', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'tentacles',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'hypotheticalAnswer' => 'none',
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function tentaclesInSmallGameReturnsError(): void
    {
        $client = static::createClient();
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'SmallTent', 'size' => 'S', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);

        $join = $client->request('POST', "/api/games/{$game['uuid']}/join", self::AUTH + [
            'json' => ['name' => 'SmallTent', 'password' => self::JOIN_PASSWORD],
        ])->toArray();
        self::assertIsString($join['playerUuid']);
        self::assertIsString($join['mercureToken']);
        $token = $join['mercureToken'];

        $client->request('POST', "/api/rounds/{$game['roundUuid']}/team", $this->headersWithToken($token) + [
            'json' => ['side' => 'seeker'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', $this->previewUrl($game['roundUuid']), $this->headersWithToken($token) + [
            'json' => [
                'askerPlayerUuid' => $join['playerUuid'],
                'category' => 'tentacles',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'featureType' => 'museum',
                'withinMeters' => 2000,
                'hypotheticalFeatureId' => '00000000-0000-0000-0000-000000000000',
                'hypotheticalAnswer' => 'nearest',
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function tentaclesNoneReturnsValidResponse(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'TentNone', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'tentacles',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'withinMeters' => 2000,
                'hypotheticalAnswer' => 'none',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $data = $response->toArray();
        $areas = $this->extractAreas($data);
        self::assertGreaterThan(0.0, $areas['current']);
        self::assertGreaterThan(0.0, $areas['projected']);
    }

    #[Test]
    public function tentaclesWithFeatureReturnsValidResponse(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'TentFeat', 'seeker');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $ctx['playerUuid'],
                'category' => 'tentacles',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'featureType' => 'museum',
                'withinMeters' => 2000,
                'hypotheticalFeatureId' => '00000000-0000-0000-0000-000000000000',
                'hypotheticalAnswer' => 'nearest',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $data = $response->toArray();
        $areas = $this->extractAreas($data);
        self::assertGreaterThan(0.0, $areas['current']);
        self::assertGreaterThan(0.0, $areas['projected']);
    }

    #[Test]
    public function aBodyPlayerUuidCannotSpoofTheActingPlayer(): void
    {
        $client = static::createClient();
        $ctx = $this->createGameAndJoinAs($client, 'SpoofTest', 'seeker');
        $hider = $this->joinAndPickSide($client, $ctx['gameUuid'], $ctx['roundUuid'], 'Bob', 'hider');

        $client->request('POST', $this->previewUrl($ctx['roundUuid']), $this->headersWithToken($ctx['token']) + [
            'json' => [
                'askerPlayerUuid' => $hider['playerUuid'],
                'category' => 'radar',
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'radiusMeters' => 1000,
                'hypotheticalAnswer' => 'inside',
            ],
        ]);

        // Identity comes from the token: a body naming a hider must not turn this into a hider action.
        self::assertResponseStatusCodeSame(201);
    }
}
