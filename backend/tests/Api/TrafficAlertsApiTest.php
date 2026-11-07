<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\Client;
use App\Entity\Game;
use App\Entity\GameGtfsLine;
use App\Entity\GtfsSource;
use App\Enum\RealtimeProvider;
use App\Repository\GameGtfsLineRepository;
use App\Repository\GameRepository;
use App\Repository\GtfsSourceRepository;
use App\Service\OutboundHttpFetcher;
use App\Service\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TrafficAlertsApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    private ?string $alertsBody = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->replaceOutboundFetcher();
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
    }

    #[Test]
    public function itReturns404ForAnUnknownGame(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/games/00000000-0000-4000-8000-000000000000/traffic-alerts', self::AUTH);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function itReturns401WithoutTheApiKey(): void
    {
        $client = $this->client();
        $game = $this->createGameWith($client);

        $client->request('GET', "/api/games/{$game['uuid']}/traffic-alerts");

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function itServesTheOperatorAlertsFilteredToTheGamesSelectedLines(): void
    {
        $this->alertsBody = $this->siriBody();

        $client = $this->client();
        $game = $this->createGameWith($client);
        $this->seedGameWithSiriSource($game['uuid']);

        $response = $client->request('GET', "/api/games/{$game['uuid']}/traffic-alerts", self::AUTH);

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame($game['uuid'], $data['uuid']);
        self::assertIsInt($data['serverNow']);

        $alerts = $data['alerts'] ?? null;
        self::assertIsArray($alerts);
        self::assertCount(2, $alerts);

        $hit = $alerts[0] ?? null;
        self::assertIsArray($hit);
        self::assertSame('hit-30', $hit['id']);
        self::assertSame('Works', $hit['channel']);
        self::assertSame('warning', $hit['severity']);
        self::assertSame('Travaux ligne 30', $hit['title']);
        self::assertSame('Déviation entre deux arrêts.', $hit['body']);
        self::assertSame(['30'], $hit['lineRefs']);
        self::assertSame([], $hit['routeIds']);
        self::assertSame([], $hit['stopIds']);
        self::assertSame(
            (new \DateTimeImmutable('2020-01-01T00:00:00+01:00'))->getTimestamp(),
            $hit['validFrom'],
        );
        self::assertSame(
            (new \DateTimeImmutable('2100-01-01T01:00:00+01:00'))->getTimestamp(),
            $hit['validUntil'],
        );
        self::assertSame('siri_lite:cts', $hit['source']);

        $network = $alerts[1] ?? null;
        self::assertIsArray($network);
        self::assertSame('network-wide', $network['id']);
        self::assertSame('info', $network['severity']);
        self::assertSame([], $network['lineRefs']);
    }

    #[Test]
    public function itResolvesAFailingOperatorFeedToAnEmptyList(): void
    {
        $client = $this->client();
        $game = $this->createGameWith($client);
        $this->seedGameWithSiriSource($game['uuid']);

        $response = $client->request('GET', "/api/games/{$game['uuid']}/traffic-alerts", self::AUTH);

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame([], $data['alerts']);
        self::assertIsInt($data['serverNow']);
    }

    /**
     * One client per test with the kernel reboot disabled, so the container service replaced in
     * setUp stays in place across the requests.
     */
    private function client(): Client
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /**
     * @return array{uuid: string, roundUuid: string}
     */
    private function createGameWith(Client $client): array
    {
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Strasbourg', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['uuid']);
        self::assertIsString($game['roundUuid']);

        return $game;
    }

    private function seedGameWithSiriSource(string $gameUuid): void
    {
        $games = self::getContainer()->get(GameRepository::class);
        self::assertInstanceOf(GameRepository::class, $games);
        $game = $games->findOneByUuid($gameUuid);
        self::assertInstanceOf(Game::class, $game);

        $sources = self::getContainer()->get(GtfsSourceRepository::class);
        self::assertInstanceOf(GtfsSourceRepository::class, $sources);
        $source = new GtfsSource('CTS feed', '/tmp/traffic-alerts-test.zip');
        $source->setRealtimeProvider(RealtimeProvider::SiriLite);
        $source->setRealtimeProfile('cts');
        $sources->save($source);

        $lines = self::getContainer()->get(GameGtfsLineRepository::class);
        self::assertInstanceOf(GameGtfsLineRepository::class, $lines);
        $lines->save(new GameGtfsLine($game, $source, 'route-30', '30', 'Line 30', '3', 'CTS', 'FF0000', null));
    }

    private function replaceOutboundFetcher(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            $body = $this->alertsBody;

            return $body === null
                ? new MockResponse('Server Error', ['http_code' => 500])
                : new MockResponse($body);
        });

        self::getContainer()->set(
            OutboundHttpFetcher::class,
            new OutboundHttpFetcher($http, new OutboundUrlGuard(), new NullLogger()),
        );
    }

    private function siriBody(): string
    {
        return (string) json_encode([
            'ServiceDelivery' => [
                'GeneralMessageDelivery' => [[
                    'InfoMessage' => [
                        [
                            'InfoMessageIdentifier' => 'hit-30',
                            'InfoChannelRef' => 'Works',
                            'ValidUntilTime' => '2100-01-01T01:00:00+01:00',
                            'Content' => [
                                'ImpactStartDateTime' => '2020-01-01T00:00:00+01:00',
                                'ImpactedLineRef' => ['30'],
                                'Message' => [
                                    [
                                        'MessageZoneRef' => 'title',
                                        'MessageText' => [['Value' => 'Travaux ligne 30', 'Lang' => 'FR']],
                                    ],
                                    [
                                        'MessageZoneRef' => 'details',
                                        'MessageText' => [
                                            ['Value' => '<p>Déviation <b>entre</b> deux arrêts.</p>', 'Lang' => 'FR'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'InfoMessageIdentifier' => 'miss-99',
                            'InfoChannelRef' => 'Works',
                            'ValidUntilTime' => '2100-01-01T01:00:00+01:00',
                            'Content' => [
                                'ImpactedLineRef' => ['99'],
                                'Message' => [
                                    [
                                        'MessageZoneRef' => 'title',
                                        'MessageText' => [['Value' => 'Ligne 99', 'Lang' => 'FR']],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'InfoMessageIdentifier' => 'expired',
                            'InfoChannelRef' => 'Works',
                            'ValidUntilTime' => '2020-01-01T00:00:00+01:00',
                            'Content' => [
                                'Message' => [
                                    [
                                        'MessageZoneRef' => 'title',
                                        'MessageText' => [['Value' => 'Expiré', 'Lang' => 'FR']],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'InfoMessageIdentifier' => 'network-wide',
                            'InfoChannelRef' => 'Information',
                            'Content' => [
                                'Message' => [
                                    [
                                        'MessageZoneRef' => 'title',
                                        'MessageText' => [['Value' => 'Info réseau', 'Lang' => 'FR']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
