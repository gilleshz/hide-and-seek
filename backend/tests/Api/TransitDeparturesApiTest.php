<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Game;
use App\Entity\GameTransitStation;
use App\Repository\GameRepository;
use App\Repository\GameTransitStationRepository;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Filesystem\Filesystem;

final class TransitDeparturesApiTest extends ApiTestCase
{
    private const string STATION_ID = 'S_0.0000_0.0000';

    private string $tilesDir;
    private string $gameUuid;

    /** @var list<string> */
    private array $gameUuids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $tilesDir = self::getContainer()->getParameter('app.tiles_dir');
        self::assertIsString($tilesDir);
        $this->tilesDir = $tilesDir;
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        foreach ($this->gameUuids as $uuid) {
            $filesystem->remove($this->tilesDir . '/' . $uuid);
        }
        parent::tearDown();
    }

    #[Test]
    public function itReturnsTheNextDeparturesForAStation(): void
    {
        $client = static::createClient();
        $this->prepare();
        $response = $client->request(
            'GET',
            "/api/games/{$this->gameUuid}/transit-stations/" . self::STATION_ID . '/departures',
            self::AUTH,
        );

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame(self::STATION_ID, $data['stationId']);
        self::assertSame('Alpha', $data['stationName']);
        self::assertSame('UTC', $data['timezone']);
        self::assertIsInt($data['serverNow']);
        $groups = $data['groups'] ?? null;
        self::assertIsArray($groups);
        self::assertCount(1, $groups);
        $group = $groups[0] ?? null;
        self::assertIsArray($group);
        self::assertSame('L1', $group['lineRef']);
        self::assertSame('B22E75', $group['colour']);
        $departures = $group['departures'] ?? null;
        self::assertIsArray($departures);
        self::assertCount(2, $departures);
        $first = $departures[0] ?? null;
        self::assertIsArray($first);
        self::assertGreaterThan(0, $first['inSeconds']);
    }

    #[Test]
    public function itReturns404ForAnUnknownStation(): void
    {
        $client = static::createClient();
        $this->prepare();
        $client->request(
            'GET',
            "/api/games/{$this->gameUuid}/transit-stations/unknown/departures",
            self::AUTH,
        );

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function itReturns404WhenTheGameHasNoTimetableIndex(): void
    {
        $client = static::createClient();
        $game = $this->createGame();
        $this->gameUuids[] = $game['uuid'];
        $this->seedStation($game['uuid'], 'S_0.0000_0.0001', 'Beta');

        $client->request(
            'GET',
            "/api/games/{$game['uuid']}/transit-stations/S_0.0000_0.0001/departures",
            self::AUTH,
        );

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function itReturnsTheWholeDayWhenAsked(): void
    {
        $client = static::createClient();
        $this->prepare();
        $response = $client->request(
            'GET',
            "/api/games/{$this->gameUuid}/transit-stations/" . self::STATION_ID . '/departures?fullDay=1',
            self::AUTH,
        );

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        $groups = $data['groups'] ?? null;
        self::assertIsArray($groups);
        self::assertCount(1, $groups);
        $group = $groups[0] ?? null;
        self::assertIsArray($group);
        $departures = $group['departures'] ?? null;
        self::assertIsArray($departures);
        self::assertCount(3, $departures);
        $first = $departures[0] ?? null;
        self::assertIsArray($first);
        self::assertSame(0, $first['inSeconds']);
        self::assertLessThanOrEqual($data['serverNow'], $first['at']);
    }

    #[Test]
    public function itReturnsAnEmptyBoardForAStationWithNoDepartureFile(): void
    {
        $client = static::createClient();
        $this->prepare();
        $this->seedStation($this->gameUuid, 'S_0.0000_9.9999', 'Gamma');

        $response = $client->request(
            'GET',
            "/api/games/{$this->gameUuid}/transit-stations/S_0.0000_9.9999/departures",
            self::AUTH,
        );

        self::assertResponseIsSuccessful();
        self::assertSame([], $response->toArray()['groups']);
    }

    private function prepare(): void
    {
        $game = $this->createGame();
        $this->gameUuid = $game['uuid'];
        $this->gameUuids[] = $game['uuid'];
        $this->seedStation($game['uuid'], self::STATION_ID, 'Alpha');
        $this->writeIndex($game['uuid'], 2);
    }

    private function seedStation(string $gameUuid, string $stationId, string $name): void
    {
        /** @var GameRepository $games */
        $games = self::getContainer()->get(GameRepository::class);
        $game = $games->findOneByUuid($gameUuid);
        self::assertInstanceOf(Game::class, $game);

        /** @var GameTransitStationRepository $stations */
        $stations = self::getContainer()->get(GameTransitStationRepository::class);
        $stations->save(new GameTransitStation($game, $stationId, $name, new Point(0.0, 0.0), ['L1']));
    }

    private function writeIndex(string $gameUuid, int $departureCount): void
    {
        $dir = $this->tilesDir . '/' . $gameUuid . '/timetable';
        (new Filesystem())->mkdir($dir);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $today = $now->format('Y-m-d');
        $base = (int) $now->format('H') * 3600 + (int) $now->format('i') * 60 + (int) $now->format('s');

        file_put_contents($dir . '/meta.json', json_encode([
            'timezone' => 'UTC',
            'sources' => [['uuid' => 'source-1']],
            'routes' => ['1' => ['ref' => 'L1', 'name' => 'Line One', 'colour' => 'B22E75']],
            'serviceDays' => [$today => ['S1']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents(
            $dir . '/stops.json',
            json_encode([self::STATION_ID => [['src' => 0, 'stopId' => 'orig-1']]], JSON_THROW_ON_ERROR),
        );

        $departures = [[
            'route' => '1',
            'service' => 'S1',
            'seconds' => max(0, $base - 3600),
            'trip' => 'TPAST',
            'direction' => 0,
            'headsign' => 'Alpha',
            'seq' => 1,
            'src' => 0,
        ]];
        for ($i = 1; $i <= $departureCount; ++$i) {
            $departures[] = [
                'route' => '1',
                'service' => 'S1',
                'seconds' => $base + $i * 3600,
                'trip' => 'T' . $i,
                'direction' => 0,
                'headsign' => 'Alpha',
                'seq' => 1,
                'src' => 0,
            ];
        }
        file_put_contents(
            $dir . '/' . sha1(self::STATION_ID) . '.json',
            json_encode($departures, JSON_THROW_ON_ERROR),
        );
    }
}
