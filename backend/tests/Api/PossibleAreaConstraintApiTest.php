<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Enum\ConstraintSource;
use App\Repository\GameRepository;
use App\Repository\PossibleAreaConstraintRepository;
use App\Repository\RoundRepository;
use PHPUnit\Framework\Attributes\Test;

final class PossibleAreaConstraintApiTest extends ApiTestCase
{
    private const string INCLUDE_RING = '{"type":"Polygon","coordinates":'
        . '[[[13.3,52.4],[13.5,52.4],[13.5,52.6],[13.3,52.6],[13.3,52.4]]]}';

    private const string EXCLUDE_RING = '{"type":"Polygon","coordinates":'
        . '[[[13.35,52.45],[13.45,52.45],[13.45,52.55],[13.35,52.55],[13.35,52.45]]]}';

    #[Test]
    public function seekerCanDrawListAndDeleteConstraints(): void
    {
        $client = static::createClient();
        [$roundUuid, $seekerUuid, $seekerToken] = $this->createRoundWithSeeker($client, 'Alice');
        $base = "/api/rounds/{$roundUuid}/possible-area-constraints";

        $included = $client->request('POST', $base, $this->headersWithToken($seekerToken) + [
            'json' => ['geoJson' => self::INCLUDE_RING, 'mode' => 'include'],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('include', $included['mode']);
        self::assertSame('manual', $included['source']);
        self::assertSame('Included area', $included['label']);
        self::assertSame('Alice', $included['createdByName']);
        self::assertIsString($included['uuid']);
        $includedUuid = $included['uuid'];

        $client->request('POST', $base, $this->headersWithToken($seekerToken) + [
            'json' => ['geoJson' => self::EXCLUDE_RING, 'mode' => 'exclude', 'label' => 'No north'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $collection = $client->request('GET', $base, $this->headersWithToken($seekerToken))->toArray();
        self::assertIsArray($collection['member']);
        self::assertCount(2, $collection['member']);
        $first = $collection['member'][0];
        self::assertIsArray($first);
        self::assertIsString($first['geoJson']);
        self::assertStringContainsString('Polygon', $first['geoJson']);
        self::assertSame('Alice', $first['createdByName']);

        $client->request('DELETE', "{$base}/{$includedUuid}", $this->headersWithToken($seekerToken));
        self::assertResponseStatusCodeSame(204);

        $afterDelete = $client->request('GET', $base, $this->headersWithToken($seekerToken))->toArray();
        self::assertIsArray($afterDelete['member']);
        self::assertCount(1, $afterDelete['member']);
        $remaining = $afterDelete['member'][0];
        self::assertIsArray($remaining);
        self::assertSame('exclude', $remaining['mode']);
        self::assertSame('No north', $remaining['label']);
    }

    #[Test]
    public function hidersCannotDrawConstraints(): void
    {
        $client = static::createClient();
        [$roundUuid, $gameUuid] = $this->createRound($client);
        [, $hiderToken] = $this->join($client, $gameUuid, $roundUuid, 'Hank', 'hider');

        $client->request('POST', "/api/rounds/{$roundUuid}/possible-area-constraints", $this->headersWithToken($hiderToken) + [
            'json' => ['geoJson' => self::INCLUDE_RING, 'mode' => 'include'],
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function listingConstraintsIsGatedLikeThePossibleArea(): void
    {
        $client = static::createClient();
        [$roundUuid, $gameUuid] = $this->createRound($client);
        [, $hiderToken] = $this->join($client, $gameUuid, $roundUuid, 'Hank', 'hider');
        $base = "/api/rounds/{$roundUuid}/possible-area-constraints";

        // Default SETUP-8 flag shares the overlay with hiders.
        $client->request('GET', $base, $this->headersWithToken($hiderToken));
        self::assertResponseIsSuccessful();

        $games = self::getContainer()->get(GameRepository::class);
        self::assertInstanceOf(GameRepository::class, $games);
        $stored = $games->findOneByUuid($gameUuid);
        self::assertNotNull($stored);
        $stored->setPossibleAreaSharedWithHiders(false);
        $games->save($stored);

        $client->request('GET', $base, $this->headersWithToken($hiderToken));
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'possible_area.seekers_only']);

        $client->request('GET', $base, self::AUTH);
        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['errorKey' => 'identity.token_missing']);
    }

    #[Test]
    public function provenConstraintsCannotBeDeleted(): void
    {
        $client = static::createClient();
        [$roundUuid, $seekerUuid, $seekerToken] = $this->createRoundWithSeeker($client, 'Alice');

        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);

        /** @var PossibleAreaConstraintRepository $constraints */
        $constraints = self::getContainer()->get(PossibleAreaConstraintRepository::class);
        $constraints->insertConstraint(
            $round,
            'POLYGON((13.3 52.4, 13.5 52.4, 13.5 52.6, 13.3 52.6, 13.3 52.4))',
            'Radar (1000m): yes',
        );
        $proven = null;
        foreach ($constraints->findByRound($round) as $candidate) {
            if ($candidate->getSource() === ConstraintSource::Proven) {
                $proven = $candidate;
            }
        }
        self::assertNotNull($proven);

        $base = "/api/rounds/{$roundUuid}/possible-area-constraints";
        $client->request('DELETE', "{$base}/{$proven->getUuid()}", $this->headersWithToken($seekerToken));
        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function deletingUnknownConstraintReturns404(): void
    {
        $client = static::createClient();
        [$roundUuid, , $seekerToken] = $this->createRoundWithSeeker($client, 'Alice');

        $base = "/api/rounds/{$roundUuid}/possible-area-constraints";
        $unknown = '00000000-0000-4000-8000-000000000000';
        $client->request('DELETE', "{$base}/{$unknown}", $this->headersWithToken($seekerToken));
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function nonSeekersCannotDeleteConstraints(): void
    {
        $client = static::createClient();
        [$roundUuid, $seekerUuid, $seekerToken, $gameUuid] = $this->createRoundWithSeekerAndGame($client, 'Alice');
        $base = "/api/rounds/{$roundUuid}/possible-area-constraints";

        $created = $client->request('POST', $base, $this->headersWithToken($seekerToken) + [
            'json' => ['geoJson' => self::INCLUDE_RING, 'mode' => 'include'],
        ])->toArray();
        self::assertIsString($created['uuid']);

        [, $hiderToken] = $this->join($client, $gameUuid, $roundUuid, 'Hank', 'hider');

        $client->request('DELETE', "{$base}/{$created['uuid']}", $this->headersWithToken($hiderToken));
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createRound(Client $client): array
    {
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);

        return [$game['roundUuid'], $game['uuid']];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function createRoundWithSeeker(Client $client, string $name): array
    {
        [$roundUuid, $gameUuid] = $this->createRound($client);
        [$seekerUuid, $seekerToken] = $this->join($client, $gameUuid, $roundUuid, $name, 'seeker');

        return [$roundUuid, $seekerUuid, $seekerToken];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function createRoundWithSeekerAndGame(Client $client, string $name): array
    {
        [$roundUuid, $gameUuid] = $this->createRound($client);
        [$seekerUuid, $seekerToken] = $this->join($client, $gameUuid, $roundUuid, $name, 'seeker');

        return [$roundUuid, $seekerUuid, $seekerToken, $gameUuid];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function join(Client $client, string $gameUuid, string $roundUuid, string $name, string $side): array
    {
        $session = $this->joinAndPickSide($client, $gameUuid, $roundUuid, $name, $side);

        return [$session['playerUuid'], $session['token']];
    }
}
