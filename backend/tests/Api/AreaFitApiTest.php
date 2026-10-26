<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Repository\FeatureRepository;
use App\Service\BoundaryService;
use App\Service\NominatimService;
use App\Service\OverpassHttpClient;
use App\Service\OverpassService;
use App\Service\TransitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AreaFitApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    private const string PREVIEW_RESPONSE = <<<'JSON'
    {"elements":[{"type":"relation","id":100,"members":[
      {"type":"way","role":"","geometry":[{"lat":46.50,"lon":6.50},{"lat":46.55,"lon":6.55}]}
    ]}]}
    JSON;

    private const string ADMIN_RESPONSE = <<<'JSON'
    {"elements":[
      {"type":"relation","id":10,"tags":{"boundary":"administrative","admin_level":"8","name":"Proche"},
       "members":[{"type":"way","role":"outer","geometry":[
         {"lat":46.49,"lon":6.49},{"lat":46.49,"lon":6.51},{"lat":46.51,"lon":6.51},
         {"lat":46.51,"lon":6.49},{"lat":46.49,"lon":6.49}]}]},
      {"type":"relation","id":11,"tags":{"boundary":"administrative","admin_level":"8","name":"Loin"},
       "members":[{"type":"way","role":"outer","geometry":[
         {"lat":47.0,"lon":7.0},{"lat":47.0,"lon":7.1},{"lat":47.1,"lon":7.1},
         {"lat":47.1,"lon":7.0},{"lat":47.0,"lon":7.0}]}]}
    ]}
    JSON;

    private const string HOLE_RESPONSE = <<<'JSON'
    {"elements":[
      {"type":"relation","id":20,"tags":{"boundary":"administrative","admin_level":"8","name":"Trou"},
       "members":[
         {"type":"way","role":"outer","geometry":[
           {"lat":46.45,"lon":6.45},{"lat":46.45,"lon":6.55},{"lat":46.55,"lon":6.55},
           {"lat":46.55,"lon":6.45},{"lat":46.45,"lon":6.45}]},
         {"type":"way","role":"inner","geometry":[
           {"lat":46.49,"lon":6.49},{"lat":46.49,"lon":6.51},{"lat":46.51,"lon":6.51},
           {"lat":46.51,"lon":6.49},{"lat":46.49,"lon":6.49}]}]},
      {"type":"relation","id":21,"tags":{"boundary":"administrative","admin_level":"8","name":"Enclave"},
       "members":[{"type":"way","role":"outer","geometry":[
         {"lat":46.495,"lon":6.495},{"lat":46.495,"lon":6.505},{"lat":46.505,"lon":6.505},
         {"lat":46.505,"lon":6.495},{"lat":46.495,"lon":6.495}]}]}
    ]}
    JSON;

    private const string DEPARTMENT_RESPONSE = <<<'JSON'
    {"elements":[
      {"type":"relation","id":20,"tags":{"boundary":"administrative","admin_level":"6","name":"DeptA"},
       "members":[{"type":"way","role":"outer","geometry":[
         {"lat":46.40,"lon":6.40},{"lat":46.40,"lon":6.60},{"lat":46.60,"lon":6.60},
         {"lat":46.60,"lon":6.40},{"lat":46.40,"lon":6.40}]}]},
      {"type":"relation","id":21,"tags":{"boundary":"administrative","admin_level":"6","name":"DeptB"},
       "members":[{"type":"way","role":"outer","geometry":[
         {"lat":46.45,"lon":6.45},{"lat":46.45,"lon":6.55},{"lat":46.55,"lon":6.55},
         {"lat":46.55,"lon":6.45},{"lat":46.45,"lon":6.45}]}]}
    ]}
    JSON;

    private const string INCOMPLETE_RESPONSE = <<<'JSON'
    {"elements":[
      {"type":"relation","id":30,"tags":{"boundary":"administrative","admin_level":"8","name":"Incomplet"},
       "members":[
         {"type":"way","role":"outer","geometry":[{"lat":46.49,"lon":6.49},{"lat":46.50,"lon":6.50}]},
         {"type":"way","role":"outer","ref":999}
       ]}
    ]}
    JSON;

    private const string NOMINATIM_LOOKUP_RESPONSE = <<<'JSON'
    [{"osm_type":"relation","osm_id":30,"display_name":"Incomplet",
      "geojson":{"type":"Polygon","coordinates":[[[6.48,46.48],[6.52,46.48],[6.52,46.52],[6.48,46.52],[6.48,46.48]]]}}]
    JSON;

    private string $adminResponse = self::ADMIN_RESPONSE;

    private string $nominatimLookupResponse = '[]';

    /** @var array<int, string> */
    private array $levelResponses = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->replaceServices();
    }

    private function replaceServices(): void
    {
        $container = static::getContainer();

        $overpassFactory = function (string $method, string $url, array $options = []): MockResponse {
            $query = '';
            $body = $options['body'] ?? '';
            if (is_string($body) && $body !== '') {
                parse_str($body, $parsed);
                if (isset($parsed['data']) && is_string($parsed['data'])) {
                    $query = $parsed['data'];
                }
            }

            if (str_contains($query, 'relation(id:')) {
                return new MockResponse(self::PREVIEW_RESPONSE);
            }

            $response = $this->adminResponse;
            if (preg_match('/admin_level"="(\d+)"/', $query, $matches) === 1) {
                $response = $this->levelResponses[(int) $matches[1]] ?? $this->adminResponse;
            }

            return new MockResponse($response);
        };
        $overpassHttpClient = new OverpassHttpClient(
            new MockHttpClient($overpassFactory),
            'https://test.example/api/',
            false,
        );

        $nominatimFactory = fn (string $method, string $url): MockResponse
            => new MockResponse($this->nominatimLookupResponse);
        $container->set(NominatimService::class, new NominatimService(
            new MockHttpClient($nominatimFactory),
            new ArrayAdapter(),
            'test-agent',
            'https://nominatim.test',
        ));

        $container->set(TransitService::class, new TransitService(
            $overpassHttpClient,
            32_000_000,
            $this->createStub(BoundaryService::class),
        ));

        /** @var FeatureRepository $features */
        $features = $container->get(FeatureRepository::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $container->set(OverpassService::class, new OverpassService(
            $overpassHttpClient,
            $features,
            $entityManager,
        ));
    }

    /** @return array<string, mixed> */
    private function fit(): array
    {
        $client = static::createClient();
        $client->request('POST', '/api/area-fit', self::AUTH + [
            'json' => ['osmLineIds' => ['100']],
        ]);
        self::assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }

    /** @param array<string, mixed> $body */
    private static function assertSingleArea(array $body, string $osmId, string $name): void
    {
        $areas = $body['areas'] ?? null;
        self::assertIsArray($areas);
        self::assertCount(1, $areas);
        $area = $areas[0];
        self::assertIsArray($area);
        self::assertSame('relation', $area['osmType'] ?? null);
        self::assertSame($osmId, $area['osmId'] ?? null);
        self::assertSame($name, $area['displayName'] ?? null);
        self::assertSame(8, $area['adminLevel'] ?? null);
    }

    #[Test]
    public function itReturnsTheCommuneContainingTheLine(): void
    {
        self::assertSingleArea($this->fit(), '10', 'Proche');
    }

    #[Test]
    public function itExcludesCommunesWhoseHoleContainsTheLine(): void
    {
        $this->adminResponse = self::HOLE_RESPONSE;

        self::assertSingleArea($this->fit(), '21', 'Enclave');
    }

    #[Test]
    public function itRecoversCommunesWhoseOverpassGeometryIsIncomplete(): void
    {
        $this->adminResponse = self::INCOMPLETE_RESPONSE;
        $this->nominatimLookupResponse = self::NOMINATIM_LOOKUP_RESPONSE;

        self::assertSingleArea($this->fit(), '30', 'Incomplet');
    }

    #[Test]
    public function itFallsBackToDepartmentsWhenCommunesExceedTheAreaLimit(): void
    {
        $this->levelResponses[8] = self::manyLevelRelations(8, 51);
        $this->levelResponses[6] = self::DEPARTMENT_RESPONSE;

        $body = $this->fit();

        $areas = $body['areas'] ?? null;
        self::assertIsArray($areas);
        self::assertCount(2, $areas);
        self::assertSame(['20', '21'], array_column($areas, 'osmId'));
        self::assertSame([6, 6], array_column($areas, 'adminLevel'));
        self::assertSame(['DeptA', 'DeptB'], array_column($areas, 'displayName'));
    }

    private static function manyLevelRelations(int $level, int $count): string
    {
        $elements = [];
        for ($i = 0; $i < $count; ++$i) {
            $elements[] = [
                'type' => 'relation',
                'id' => 1000 + $i,
                'tags' => [
                    'boundary' => 'administrative',
                    'admin_level' => (string) $level,
                    'name' => 'Area ' . $i,
                ],
                'members' => [[
                    'type' => 'way',
                    'role' => 'outer',
                    'geometry' => [
                        ['lat' => 46.49, 'lon' => 6.49],
                        ['lat' => 46.49, 'lon' => 6.51],
                        ['lat' => 46.51, 'lon' => 6.51],
                        ['lat' => 46.51, 'lon' => 6.49],
                        ['lat' => 46.49, 'lon' => 6.49],
                    ],
                ]],
            ];
        }

        return json_encode(['elements' => $elements], JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function itRejectsARequestWithoutLines(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/area-fit', self::AUTH + ['json' => []]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'area_fit.no_lines']);
    }

    #[Test]
    public function itRejectsAFitThatFindsNoCommune(): void
    {
        $this->adminResponse = '{"elements":[]}';

        $client = static::createClient();
        $client->request('POST', '/api/area-fit', self::AUTH + [
            'json' => ['osmLineIds' => ['100']],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'area_fit.no_areas']);
    }
}
