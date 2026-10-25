<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Service\GtfsShapeCompletionService;
use App\Service\OverpassHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GtfsShapeCompletionServiceTest extends TestCase
{
    private const string DISCOVERY = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":100,"tags":{"route":"tram","ref":"A"}},
         {"type":"relation","id":200,"tags":{"route":"bus","ref":"B"}}
        ]}
        JSON;

    private const string GEOMETRY = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":100,"members":[
           {"type":"node","role":"stop","lat":48.5000,"lon":7.7000},
           {"type":"way","role":"platform","geometry":[{"lat":48.4900,"lon":7.6900},{"lat":48.4900,"lon":7.7300}]},
           {"type":"way","role":"","geometry":[{"lat":48.5001,"lon":7.6999},{"lat":48.5001,"lon":7.7050}]},
           {"type":"way","role":"","geometry":[{"lat":48.5001,"lon":7.7050},{"lat":48.5001,"lon":7.7201}]}
         ]},
         {"type":"relation","id":200,"members":[
           {"type":"way","role":"","geometry":[
             {"lat":48.5100,"lon":7.7000},{"lat":48.5100,"lon":7.7100},{"lat":48.5100,"lon":7.7200}
           ]}
         ]}
        ]}
        JSON;

    private const string GEOMETRY_TRUNCATED = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":100,"members":[
           {"type":"way","role":"","geometry":[{"lat":48.5001,"lon":7.6999},{"lat":48.5001,"lon":7.7050}]},
           {"type":"way","role":"","geometry":[]}
         ]},
         {"type":"relation","id":200,"members":[
           {"type":"way","role":"","geometry":[
             {"lat":48.5100,"lon":7.7000},{"lat":48.5100,"lon":7.7100},{"lat":48.5100,"lon":7.7200}
           ]}
         ]}
        ]}
        JSON;

    private const string DISCOVERY_TWO_SAME_REF = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":100,"tags":{"route":"tram","ref":"A"}},
         {"type":"relation","id":101,"tags":{"route":"tram","ref":"A"}}
        ]}
        JSON;

    private const string GEOMETRY_TWO_SAME_REF = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":100,"members":[
           {"type":"way","role":"","geometry":[{"lat":48.5001,"lon":7.6999},{"lat":48.5001,"lon":7.7201}]}
         ]},
         {"type":"relation","id":101,"members":[
           {"type":"way","role":"","geometry":[{"lat":48.5011,"lon":7.6999},{"lat":48.5011,"lon":7.7201}]}
         ]}
        ]}
        JSON;

    private const string DISCOVERY_OTHER_REF = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":999,"tags":{"route":"tram","ref":"Z"}}
        ]}
        JSON;

    private const string DISCOVERY_FOUR_SAME_REF = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":100,"tags":{"route":"tram","ref":"A"}},
         {"type":"relation","id":101,"tags":{"route":"tram","ref":"A"}},
         {"type":"relation","id":102,"tags":{"route":"tram","ref":"A"}},
         {"type":"relation","id":103,"tags":{"route":"tram","ref":"A"}}
        ]}
        JSON;

    private const string GEOMETRY_SPLIT_WITH_OPPOSITES = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":100,"members":[
           {"type":"way","role":"","geometry":[{"lat":48.5001,"lon":7.6999},{"lat":48.5001,"lon":7.7050}]}
         ]},
         {"type":"relation","id":101,"members":[
           {"type":"way","role":"","geometry":[{"lat":48.5001,"lon":7.7051},{"lat":48.5001,"lon":7.6998}]}
         ]},
         {"type":"relation","id":102,"members":[
           {"type":"way","role":"","geometry":[{"lat":48.5001,"lon":7.7100},{"lat":48.5001,"lon":7.7201}]}
         ]},
         {"type":"relation","id":103,"members":[
           {"type":"way","role":"","geometry":[{"lat":48.5001,"lon":7.7202},{"lat":48.5001,"lon":7.7099}]}
         ]}
        ]}
        JSON;

    private const string GEOMETRY_SPLIT_SAME_REF = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":100,"members":[
           {"type":"way","role":"","geometry":[{"lat":48.5001,"lon":7.6999},{"lat":48.5001,"lon":7.7100}]}
         ]},
         {"type":"relation","id":101,"members":[
           {"type":"way","role":"","geometry":[{"lat":48.5001,"lon":7.7100},{"lat":48.5001,"lon":7.7201}]}
         ]}
        ]}
        JSON;

    private const string GEOMETRY_NO_WAYS = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":100,"members":[]},
         {"type":"relation","id":200,"members":[]}
        ]}
        JSON;

    private const string GEOMETRY_B_ONLY = <<<'JSON'
        {"version":0.6,"elements":[
         {"type":"relation","id":200,"members":[
           {"type":"way","role":"","geometry":[
             {"lat":48.5100,"lon":7.7000},{"lat":48.5100,"lon":7.7100},{"lat":48.5100,"lon":7.7200}
           ]}
         ]}
        ]}
        JSON;

    private const string MALFORMED_JSON = '{"elements": ';

    /** @var list<string> */
    private const array STRASBOURG_REFS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];

        parent::tearDown();
    }

    #[Test]
    public function itCompletesMissingShapesAndPreservesTheArchive(): void
    {
        $path = $this->createZip($this->fixture());
        $client = new MockHttpClient([
            new MockResponse(self::DISCOVERY),
            new MockResponse(self::GEOMETRY),
        ]);

        $result = $this->createService($client)->completeIfNeeded($path);

        self::assertSame(['completed' => true, 'shapes' => 2, 'routes' => 2, 'unmatched' => []], $result);

        $zip = $this->openZip($path);
        $shapes = $this->parseCsv((string) $zip->getFromName('shapes.txt'));
        $trips = $this->parseCsv((string) $zip->getFromName('trips.txt'));
        self::assertSame("keep me\n", $zip->getFromName('notes/unrelated.txt'));
        $zip->close();

        self::assertSame(
            ['shape_id', 'shape_pt_lat', 'shape_pt_lon', 'shape_pt_sequence', 'shape_dist_traveled'],
            $shapes['header'],
        );
        $tripRows = $trips['rows'];
        self::assertCount(2, $tripRows);
        self::assertSame('OSM_100', $tripRows[0]['shape_id']);
        self::assertSame('OSM_200', $tripRows[1]['shape_id']);
        self::assertSame('B1', $tripRows[0]['block_id']);
        self::assertSame('B2', $tripRows[1]['block_id']);
        self::assertSame(['route_id', 'service_id', 'trip_id', 'block_id', 'shape_id'], $trips['header']);

        $shape100 = array_values(array_filter(
            $shapes['rows'],
            static fn (array $row): bool => $row['shape_id'] === 'OSM_100',
        ));
        self::assertCount(3, $shape100);
        self::assertSame(['1', '2', '3'], array_column($shape100, 'shape_pt_sequence'));
        self::assertSame('48.5001000', $shape100[0]['shape_pt_lat']);
    }

    #[Test]
    public function itRetriesAGeometryChunkWhenAMirrorOmitsMemberWays(): void
    {
        $path = $this->createZip($this->fixture());
        $client = new MockHttpClient([
            new MockResponse(self::DISCOVERY),
            new MockResponse(self::GEOMETRY_TRUNCATED),
            new MockResponse(self::GEOMETRY),
        ]);

        $result = $this->createService($client)->completeIfNeeded($path);

        self::assertSame(['completed' => true, 'shapes' => 2, 'routes' => 2, 'unmatched' => []], $result);

        $zip = $this->openZip($path);
        $shapes = $this->parseCsv((string) $zip->getFromName('shapes.txt'));
        $trips = $this->parseCsv((string) $zip->getFromName('trips.txt'));
        $zip->close();

        self::assertSame(['OSM_100', 'OSM_200'], array_column($trips['rows'], 'shape_id'));
        $shape100 = array_values(array_filter(
            $shapes['rows'],
            static fn (array $row): bool => $row['shape_id'] === 'OSM_100',
        ));
        self::assertCount(3, $shape100);
        self::assertSame('7.7201000', $shape100[2]['shape_pt_lon']);
    }

    #[Test]
    public function itPicksTheNearestCandidateWhenRefsCollide(): void
    {
        $path = $this->createZip($this->fixture());
        $client = new MockHttpClient([
            new MockResponse(self::DISCOVERY_TWO_SAME_REF),
            new MockResponse(self::GEOMETRY_TWO_SAME_REF),
        ]);

        $result = $this->createService($client)->completeIfNeeded($path);

        self::assertSame(['completed' => true, 'shapes' => 1, 'routes' => 1, 'unmatched' => ['B']], $result);

        $zip = $this->openZip($path);
        $trips = $this->parseCsv((string) $zip->getFromName('trips.txt'));
        $zip->close();

        $tripRows = $trips['rows'];
        self::assertCount(2, $tripRows);
        self::assertSame('OSM_100', $tripRows[0]['shape_id']);
        self::assertSame('', $tripRows[1]['shape_id']);
    }

    #[Test]
    public function itLeavesTheArchiveUntouchedWhenNoOsmRefMatches(): void
    {
        $path = $this->createZip($this->fixture());
        $before = hash_file('sha256', $path);
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(self::DISCOVERY_OTHER_REF);
        });

        $result = $this->createService($client)->completeIfNeeded($path);

        self::assertSame(['completed' => false, 'shapes' => 0, 'routes' => 0, 'unmatched' => ['A', 'B']], $result);
        self::assertSame(1, $requests);
        self::assertSame($before, hash_file('sha256', $path));

        $zip = $this->openZip($path);
        self::assertFalse($zip->locateName('shapes.txt'));
        $zip->close();
    }

    #[Test]
    public function itSkipsAFeedThatAlreadyHasShapes(): void
    {
        $path = $this->createZip($this->fixtureWithShapes());
        $before = hash_file('sha256', $path);
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(self::DISCOVERY);
        });

        $result = $this->createService($client)->completeIfNeeded($path);

        self::assertSame(['completed' => true, 'shapes' => 0, 'routes' => 0, 'unmatched' => []], $result);
        self::assertSame(0, $requests);
        self::assertSame($before, hash_file('sha256', $path));
        self::assertSame([], glob($path . '.*') ?: []);
    }

    #[Test]
    public function itCompletesShapesWhenStopTimesRowsAreInterleaved(): void
    {
        $path = $this->createZip($this->fixtureWithInterleavedStopTimes());
        $client = new MockHttpClient([
            new MockResponse(self::DISCOVERY),
            new MockResponse(self::GEOMETRY),
        ]);

        $result = $this->createService($client)->completeIfNeeded($path);

        self::assertSame(['completed' => true, 'shapes' => 2, 'routes' => 2, 'unmatched' => []], $result);

        $zip = $this->openZip($path);
        $trips = $this->parseCsv((string) $zip->getFromName('trips.txt'));
        $zip->close();

        self::assertSame(['OSM_100', 'OSM_200'], array_column($trips['rows'], 'shape_id'));
        self::assertSame([], glob($path . '.*') ?: []);
    }

    #[Test]
    public function itMergesSameRefRelationsWhenNoSingleRelationCoversTheRoute(): void
    {
        $path = $this->createZip($this->fixture());
        $client = new MockHttpClient([
            new MockResponse(self::DISCOVERY_TWO_SAME_REF),
            new MockResponse(self::GEOMETRY_SPLIT_SAME_REF),
        ]);

        $result = $this->createService($client)->completeIfNeeded($path);

        self::assertSame(['completed' => true, 'shapes' => 1, 'routes' => 1, 'unmatched' => ['B']], $result);

        $zip = $this->openZip($path);
        $trips = $this->parseCsv((string) $zip->getFromName('trips.txt'));
        $shapes = $this->parseCsv((string) $zip->getFromName('shapes.txt'));
        $zip->close();

        self::assertSame('OSM_100', $trips['rows'][0]['shape_id']);
        self::assertSame('', $trips['rows'][1]['shape_id']);
        $merged = array_values(array_filter(
            $shapes['rows'],
            static fn (array $row): bool => $row['shape_id'] === 'OSM_100',
        ));
        self::assertCount(3, $merged);
        self::assertSame('7.7201000', $merged[2]['shape_pt_lon']);
    }

    #[Test]
    public function itJoinsSplitRelationsAndDropsOppositeDirections(): void
    {
        $path = $this->createZip($this->fixture());
        $client = new MockHttpClient([
            new MockResponse(self::DISCOVERY_FOUR_SAME_REF),
            new MockResponse(self::GEOMETRY_SPLIT_WITH_OPPOSITES),
        ]);

        $result = $this->createService($client)->completeIfNeeded($path);

        self::assertSame(['completed' => true, 'shapes' => 1, 'routes' => 1, 'unmatched' => ['B']], $result);

        $zip = $this->openZip($path);
        $trips = $this->parseCsv((string) $zip->getFromName('trips.txt'));
        $shapes = $this->parseCsv((string) $zip->getFromName('shapes.txt'));
        $zip->close();

        self::assertSame('OSM_100', $trips['rows'][0]['shape_id']);
        $merged = array_values(array_filter(
            $shapes['rows'],
            static fn (array $row): bool => $row['shape_id'] === 'OSM_100',
        ));
        self::assertCount(4, $merged);
        self::assertGreaterThanOrEqual(7.72, (float) $merged[3]['shape_pt_lon']);
    }

    #[Test]
    public function itLeavesTheArchiveUntouchedWhenDiscoveryJsonIsMalformed(): void
    {
        $path = $this->createZip($this->fixture());
        $before = hash_file('sha256', $path);
        $client = new MockHttpClient([new MockResponse(self::MALFORMED_JSON)]);

        try {
            $this->createService($client)->completeIfNeeded($path);
            self::fail('Expected the malformed discovery response to fail completion.');
        } catch (FunctionalException $e) {
            self::assertSame(ErrorKey::GTFS_SHAPES_MISSING, $e->getErrorKey());
        }

        self::assertSame($before, hash_file('sha256', $path));
        self::assertSame([], glob($path . '.*') ?: []);
    }

    #[Test]
    public function itLeavesTheArchiveUntouchedWhenGeometryIsUnusable(): void
    {
        $path = $this->createZip($this->fixture());
        $before = hash_file('sha256', $path);
        $client = new MockHttpClient([
            new MockResponse(self::DISCOVERY),
            new MockResponse(self::GEOMETRY_NO_WAYS),
        ]);

        try {
            $this->createService($client)->completeIfNeeded($path);
            self::fail('Expected the empty geometry response to fail completion.');
        } catch (FunctionalException $e) {
            self::assertSame(ErrorKey::GTFS_SHAPES_MISSING, $e->getErrorKey());
        }

        self::assertSame($before, hash_file('sha256', $path));
        self::assertSame([], glob($path . '.*') ?: []);
    }

    #[Test]
    public function itAppendsCompletedShapesToAnExistingShapesFile(): void
    {
        $path = $this->createZip($this->fixtureWithPartialShapes());
        $client = new MockHttpClient([
            new MockResponse(self::DISCOVERY),
            new MockResponse(self::GEOMETRY_B_ONLY),
        ]);

        $result = $this->createService($client)->completeIfNeeded($path);

        self::assertSame(['completed' => true, 'shapes' => 1, 'routes' => 1, 'unmatched' => []], $result);

        $zip = $this->openZip($path);
        $trips = $this->parseCsv((string) $zip->getFromName('trips.txt'));
        $shapes = $this->parseCsv((string) $zip->getFromName('shapes.txt'));
        $zip->close();

        self::assertSame(['SH1', 'OSM_200'], array_column($trips['rows'], 'shape_id'));
        $existing = array_values(array_filter(
            $shapes['rows'],
            static fn (array $row): bool => $row['shape_id'] === 'SH1',
        ));
        self::assertCount(2, $existing);
        self::assertSame('48.5000', $existing[0]['shape_pt_lat']);
        self::assertSame([], glob($path . '.*') ?: []);
    }

    #[Test]
    public function itCompletesTramRefsAFAndBusRefsGAndH(): void
    {
        $path = $this->createZip($this->tramAndBusFixture());
        $client = new MockHttpClient([
            new MockResponse($this->tramAndBusDiscovery()),
            new MockResponse($this->tramAndBusGeometry()),
        ]);

        $result = $this->createService($client)->completeIfNeeded($path);

        self::assertSame(['completed' => true, 'shapes' => 8, 'routes' => 8, 'unmatched' => []], $result);

        $zip = $this->openZip($path);
        $trips = $this->parseCsv((string) $zip->getFromName('trips.txt'));
        $zip->close();

        self::assertCount(8, $trips['rows']);
        self::assertSame([
            'OSM_100',
            'OSM_101',
            'OSM_102',
            'OSM_103',
            'OSM_104',
            'OSM_105',
            'OSM_106',
            'OSM_107',
        ], array_column($trips['rows'], 'shape_id'));
        self::assertSame([], glob($path . '.*') ?: []);
    }

    private function createService(MockHttpClient $client): GtfsShapeCompletionService
    {
        return new GtfsShapeCompletionService(
            new OverpassHttpClient($client, 'https://test.example/api/', false, new NullLogger()),
            new NullLogger(),
            32_000_000,
        );
    }

    /**
     * @return array<string, string>
     */
    private function fixture(): array
    {
        return [
            'agency.txt' => "agency_id,agency_name,agency_url,agency_timezone\n"
                . "A1,CTS,https://example.test,Europe/Paris\n",
            'routes.txt' => "route_id,route_short_name,route_long_name,route_type\n"
                . "RA,a,Line A,0\n"
                . "RB,B,Line B,3\n",
            'trips.txt' => "route_id,service_id,trip_id,block_id\n"
                . "RA,S1,TA1,B1\n"
                . "RB,S1,TB1,B2\n",
            'stops.txt' => "stop_id,stop_name,stop_lat,stop_lon\n"
                . "SA1,Alpha,48.5000,7.7000\n"
                . "SA2,Beta,48.5000,7.7100\n"
                . "SA3,Gamma,48.5000,7.7200\n"
                . "SB1,Delta,48.5100,7.7000\n"
                . "SB2,Epsilon,48.5100,7.7100\n"
                . "SB3,Zeta,48.5100,7.7200\n"
                . "SB4,Invalid,200.0000,7.7100\n",
            'stop_times.txt' => "trip_id,arrival_time,departure_time,stop_id,stop_sequence\n"
                . "TA1,08:00:00,08:00:00,SA1,1\n"
                . "TA1,08:05:00,08:05:00,SA2,2\n"
                . "TA1,08:10:00,08:10:00,SA3,3\n"
                . "TB1,09:00:00,09:00:00,SB1,1\n"
                . "TB1,09:05:00,09:05:00,SB2,2\n"
                . "TB1,09:10:00,09:10:00,SB3,3\n",
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,"
                . "start_date,end_date\n"
                . "S1,1,1,1,1,1,0,0,20260101,20261231\n",
            'notes/unrelated.txt' => "keep me\n",
        ];
    }

    /**
     * @return array<string, string>
     */
    private function fixtureWithShapes(): array
    {
        $files = $this->fixture();
        $files['trips.txt'] = "route_id,service_id,trip_id,block_id,shape_id\n"
            . "RA,S1,TA1,B1,SH1\n"
            . "RB,S1,TB1,B2,SH1\n";
        $files['shapes.txt'] = "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\n"
            . "SH1,48.5000,7.7000,1\n"
            . "SH1,48.5000,7.7200,2\n";

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function fixtureWithInterleavedStopTimes(): array
    {
        $files = $this->fixture();
        $files['stop_times.txt'] = "trip_id,arrival_time,departure_time,stop_id,stop_sequence\n"
            . "TA1,08:05:00,08:05:00,SA2,2\n"
            . "TB1,09:00:00,09:00:00,SB1,1\n"
            . "TA1,08:10:00,08:10:00,SA3,3\n"
            . "TB1,09:05:00,09:05:00,SB2,2\n"
            . "TA1,08:00:00,08:00:00,SA1,1\n"
            . "TB1,09:10:00,09:10:00,SB3,3\n";

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function fixtureWithPartialShapes(): array
    {
        $files = $this->fixture();
        $files['trips.txt'] = "route_id,service_id,trip_id,block_id,shape_id\n"
            . "RA,S1,TA1,B1,SH1\n"
            . "RB,S1,TB1,B2,\n";
        $files['shapes.txt'] = "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\n"
            . "SH1,48.5000,7.7000,1\n"
            . "SH1,48.5000,7.7200,2\n";

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function tramAndBusFixture(): array
    {
        $routes = "route_id,route_short_name,route_long_name,route_type\n";
        $trips = "route_id,service_id,trip_id,block_id\n";
        $stops = "stop_id,stop_name,stop_lat,stop_lon\n";
        $stopTimes = "trip_id,stop_id,stop_sequence\n";
        foreach (self::STRASBOURG_REFS as $index => $ref) {
            $routeId = 'R' . $ref;
            $tripId = 'T' . $ref;
            $lat = 48.50 + $index * 0.01;
            $shortName = $index % 2 === 0 ? mb_strtolower($ref) : $ref;
            $type = $index < 6 ? 0 : 3;
            $routes .= "{$routeId},{$shortName},Line {$ref},{$type}\n";
            $trips .= "{$routeId},S1,{$tripId},B{$index}\n";
            for ($stop = 1; $stop <= 3; ++$stop) {
                $stopId = 'S' . $ref . $stop;
                $lon = 7.70 + ($stop - 1) * 0.01;
                $stops .= $stopId . ',Stop ' . $ref . $stop . ','
                    . number_format($lat, 4, '.', '') . ',' . number_format($lon, 4, '.', '') . "\n";
                $stopTimes .= "{$tripId},{$stopId},{$stop}\n";
            }
        }

        return [
            'agency.txt' => "agency_id,agency_name,agency_url,agency_timezone\n"
                . "A1,CTS,https://example.test,Europe/Paris\n",
            'routes.txt' => $routes,
            'trips.txt' => $trips,
            'stops.txt' => $stops,
            'stop_times.txt' => $stopTimes,
        ];
    }

    private function tramAndBusDiscovery(): string
    {
        $elements = [];
        foreach (self::STRASBOURG_REFS as $index => $ref) {
            $elements[] = [
                'type' => 'relation',
                'id' => 100 + $index,
                'tags' => ['route' => $index < 6 ? 'tram' : 'bus', 'ref' => $ref],
            ];
        }

        return (string) json_encode(['version' => 0.6, 'elements' => $elements]);
    }

    private function tramAndBusGeometry(): string
    {
        $elements = [];
        foreach (self::STRASBOURG_REFS as $index => $ref) {
            $lat = 48.50 + $index * 0.01;
            $elements[] = [
                'type' => 'relation',
                'id' => 100 + $index,
                'members' => [
                    [
                        'type' => 'way',
                        'role' => '',
                        'geometry' => [
                            ['lat' => $lat, 'lon' => 7.6999],
                            ['lat' => $lat, 'lon' => 7.7100],
                            ['lat' => $lat, 'lon' => 7.7201],
                        ],
                    ],
                ],
            ];
        }

        return (string) json_encode(['version' => 0.6, 'elements' => $elements]);
    }

    /**
     * @param array<string, string> $files
     */
    private function createZip(array $files): string
    {
        $path = sys_get_temp_dir() . '/gtfs-shape-completion-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $this->paths[] = $path;

        return $path;
    }

    private function openZip(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));

        return $zip;
    }

    /**
     * @return array{header: list<string>, rows: list<array<string, string>>}
     */
    private function parseCsv(string $csv): array
    {
        $lines = array_values(array_filter(
            explode("\n", trim($csv)),
            static fn (string $line): bool => $line !== '',
        ));
        if ($lines === []) {
            return ['header' => [], 'rows' => []];
        }

        $header = array_map('strval', str_getcsv($lines[0], ',', '"', ''));
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $cells = array_map('strval', str_getcsv($line, ',', '"', ''));
            $row = [];
            foreach ($header as $index => $column) {
                $row[$column] = $cells[$index] ?? '';
            }
            $rows[] = $row;
        }

        return ['header' => $header, 'rows' => $rows];
    }
}
