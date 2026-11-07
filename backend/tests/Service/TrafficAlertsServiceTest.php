<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\TrafficAlert;
use App\Entity\Game;
use App\Entity\GameGtfsLine;
use App\Entity\GtfsSource;
use App\Enum\AlertSeverity;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RealtimeProvider;
use App\Exception\EntityNotFoundException;
use App\Repository\GameGtfsLineRepository;
use App\Repository\GameRepository;
use App\Service\OutboundHttpFetcher;
use App\Service\OutboundUrlGuard;
use App\Service\SiriAlertsClient;
use App\Service\TrafficAlertsService;
use App\Service\TransitRealtimeService;
use App\Tests\Support\TestSiriProfiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TrafficAlertsServiceTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    #[Test]
    public function itKeepsAlertsMatchingByRouteIdOrByFoldedLineRefAndNetworkWideOnes(): void
    {
        $service = $this->service();

        $alerts = [
            $this->alert('a1', routeIds: ['R1']),
            $this->alert('a2', lineRefs: ['c1']),
            $this->alert('a3', lineRefs: ['TÉtoile']),
            $this->alert('a4', routeIds: ['R9'], lineRefs: ['99']),
            $this->alert('a5'),
        ];

        $kept = $service->filter($alerts, ['R1'], ['C1', 'tÉtoile'], self::NOW);

        self::assertSame(['a1', 'a2', 'a3', 'a5'], array_map(static fn (TrafficAlert $a): string => $a->id, $kept));
    }

    #[Test]
    public function itDropsAlertsOutsideTheirValidityWindow(): void
    {
        $service = $this->service();

        $alerts = [
            $this->alert('expired', validUntil: self::NOW - 1),
            $this->alert('future', validFrom: self::NOW + 1),
            $this->alert('edge-until', validUntil: self::NOW),
            $this->alert('edge-from', validFrom: self::NOW),
            $this->alert('open'),
        ];

        $kept = $service->filter($alerts, [], [], self::NOW);

        self::assertSame(
            ['edge-until', 'open', 'edge-from'],
            array_map(static fn (TrafficAlert $a): string => $a->id, $kept),
        );
    }

    #[Test]
    public function itDedupesByIdAndSortsBySeverityThenValidFrom(): void
    {
        $service = $this->service();

        $alerts = [
            $this->alert('dup', severity: AlertSeverity::Info, validFrom: 5),
            $this->alert('severe', severity: AlertSeverity::Severe, validFrom: 100),
            $this->alert('warning-late', severity: AlertSeverity::Warning, validFrom: 50),
            $this->alert('dup', severity: AlertSeverity::Severe, validFrom: 1),
            $this->alert('warning-early', severity: AlertSeverity::Warning, validFrom: 10),
            $this->alert('info-open', severity: AlertSeverity::Info),
        ];

        $kept = $service->filter($alerts, [], [], self::NOW);

        self::assertSame(
            ['severe', 'warning-early', 'warning-late', 'info-open', 'dup'],
            array_map(static fn (TrafficAlert $a): string => $a->id, $kept),
        );
    }

    #[Test]
    public function itThrowsNotFoundForAnUnknownGame(): void
    {
        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn(null);

        $service = $this->service($games);

        $this->expectException(EntityNotFoundException::class);
        $service->forGame('nope', self::NOW);
    }

    #[Test]
    public function itServesTheSiriAlertsOfTheGamesSourcesFilteredToTheSelectedLines(): void
    {
        $body = (string) json_encode([
            'ServiceDelivery' => [
                'GeneralMessageDelivery' => [[
                    'InfoMessage' => [
                        [
                            'InfoMessageIdentifier' => 'hit-30',
                            'InfoChannelRef' => 'Works',
                            'ValidUntilTime' => '2100-01-01T01:00:00+01:00',
                            'Content' => [
                                'ImpactedLineRef' => ['30'],
                                'Message' => [
                                    [
                                        'MessageZoneRef' => 'title',
                                        'MessageText' => [['Value' => 'Ligne 30', 'Lang' => 'FR']],
                                    ],
                                    [
                                        'MessageZoneRef' => 'details',
                                        'MessageText' => [['Value' => '<p>Détails 30</p>', 'Lang' => 'FR']],
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
                            'InfoMessageIdentifier' => 'network',
                            'InfoChannelRef' => 'Information',
                            'Content' => [
                                'Message' => [
                                    [
                                        'MessageZoneRef' => 'title',
                                        'MessageText' => [['Value' => 'Réseau', 'Lang' => 'FR']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $requests = [];
        $http = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$requests, $body): MockResponse {
                $requests[] = $url;

                return new MockResponse($body);
            },
        );

        $game = new Game('Strasbourg', GameSize::Medium, Edition::Metric);
        $source = new GtfsSource('CTS', '/tmp/cts.zip');
        $source->setRealtimeProvider(RealtimeProvider::SiriLite);
        $source->setRealtimeProfile('cts');
        $line = new GameGtfsLine($game, $source, 'route-30', '30', 'Line 30', '3', 'CTS', null, null);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);
        $lines = $this->createStub(GameGtfsLineRepository::class);
        $lines->method('findByGame')->willReturn([$line]);

        $service = $this->service($games, $lines, $http);
        $result = $service->forGame($game->getUuid(), self::NOW);

        self::assertSame(self::NOW, $result['serverNow']);
        self::assertSame(
            ['hit-30', 'network'],
            array_map(static fn (TrafficAlert $a): string => $a->id, $result['alerts']),
        );
        self::assertSame('Détails 30', $result['alerts'][0]->body);
        self::assertSame('siri_lite:cts', $result['alerts'][0]->source);
        self::assertSame(['https://1.1.1.1/cts-alerts'], $requests);
    }

    #[Test]
    public function itResolvesAFailingOperatorFetchToAnEmptyList(): void
    {
        $http = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('Server Error', ['http_code' => 500]),
        );

        $game = new Game('Strasbourg', GameSize::Medium, Edition::Metric);
        $source = new GtfsSource('CTS', '/tmp/cts.zip');
        $source->setRealtimeProvider(RealtimeProvider::SiriLite);
        $source->setRealtimeProfile('cts');
        $line = new GameGtfsLine($game, $source, 'route-30', '30', 'Line 30', '3', 'CTS', null, null);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);
        $lines = $this->createStub(GameGtfsLineRepository::class);
        $lines->method('findByGame')->willReturn([$line]);

        $service = $this->service($games, $lines, $http);
        $result = $service->forGame($game->getUuid(), self::NOW);

        self::assertSame([], $result['alerts']);
    }

    /**
     * @param list<string> $lineRefs
     * @param list<string> $routeIds
     */
    private function alert(
        string $id,
        array $lineRefs = [],
        array $routeIds = [],
        AlertSeverity $severity = AlertSeverity::Info,
        ?int $validFrom = null,
        ?int $validUntil = null,
    ): TrafficAlert {
        return new TrafficAlert(
            id: $id,
            channel: 'channel',
            severity: $severity,
            title: 'title ' . $id,
            body: 'body ' . $id,
            lineRefs: $lineRefs,
            routeIds: $routeIds,
            stopIds: [],
            validFrom: $validFrom,
            validUntil: $validUntil,
            source: 'test',
        );
    }

    private function service(
        ?GameRepository $games = null,
        ?GameGtfsLineRepository $lines = null,
        ?MockHttpClient $http = null,
    ): TrafficAlertsService {
        $fetcher = new OutboundHttpFetcher($http ?? new MockHttpClient(), new OutboundUrlGuard(), new NullLogger());

        return new TrafficAlertsService(
            $games ?? $this->createStub(GameRepository::class),
            $lines ?? $this->createStub(GameGtfsLineRepository::class),
            new TransitRealtimeService($fetcher, new ArrayAdapter(), new NullLogger()),
            new SiriAlertsClient($fetcher, new ArrayAdapter(), new NullLogger()),
            TestSiriProfiles::registry(),
            new NullLogger(),
        );
    }
}
