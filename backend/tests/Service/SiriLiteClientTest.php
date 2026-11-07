<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\RealtimeSelection;
use App\Enum\RealtimeProvider;
use App\Service\OutboundHttpFetcher;
use App\Service\OutboundUrlGuard;
use App\Service\SiriLiteClient;
use App\Service\SiriProfileRegistry;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\TestSiriProfiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SiriLiteClientTest extends TestCase
{
    #[Test]
    public function itFetchesIdfmStopMonitoringWithTheTemplatedStopRef(): void
    {
        $requests = [];
        $http = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['url' => $url, 'headers' => $this->headerLines($options)];

                return new MockResponse($this->idfmBody());
            },
        );
        $client = $this->client($http);

        $visits = $client->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'idfm'),
            ['IDFM:22104', 'child-1'],
            ['IDFM:C01624'],
        );

        self::assertCount(1, $requests);
        self::assertStringContainsString('MonitoringRef=STIF%3AStopPoint%3AQ%3A22104%3A', $requests[0]['url']);
        self::assertContains('apiKey: test-prim-key', $requests[0]['headers']);
        self::assertCount(1, $visits);
        $visit = $visits[0];
        self::assertSame('IDFM:C01624', $visit->lineCode);
        self::assertSame('STIF:StopPoint:Q:22104:', $visit->stopCode);
        self::assertSame('STIF:VehicleJourney:123', $visit->tripRef);
        self::assertSame(
            (new \DateTimeImmutable('2026-11-06T13:00:00+01:00'))->getTimestamp(),
            $visit->aimedAt,
        );
        self::assertSame(
            (new \DateTimeImmutable('2026-11-06T13:03:00+01:00'))->getTimestamp(),
            $visit->expectedAt,
        );
        self::assertSame(3, $visit->stopSequence);
        self::assertSame('Gare de Lyon', $visit->destination);
        self::assertFalse($visit->cancelled);
    }

    #[Test]
    public function itUsesTheVisitMonitoringRefWhenTheCallHasNoStopPointRef(): void
    {
        $body = json_encode([
            'Siri' => [
                'ServiceDelivery' => [
                    'StopMonitoringDelivery' => [[
                        'MonitoredStopVisit' => [[
                            'MonitoringRef' => ['value' => 'STIF:StopPoint:Q:555:'],
                            'MonitoredVehicleJourney' => [
                                'LineRef' => ['value' => 'STIF:Line::C3:'],
                                'MonitoredCall' => [
                                    'AimedDepartureTime' => '2026-11-06T13:00:00+01:00',
                                    'ExpectedDepartureTime' => '2026-11-06T13:03:00+01:00',
                                ],
                            ],
                        ]],
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $client = $this->client(new MockHttpClient(new MockResponse($body)));

        $visits = $client->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'idfm'),
            ['555'],
            [],
        );

        self::assertCount(1, $visits);
        self::assertSame('STIF:StopPoint:Q:555:', $visits[0]->stopCode);
    }

    #[Test]
    public function itFallsBackToTheNextStopRefWhenTheFirstReturnsNoVisits(): void
    {
        $requested = [];
        $http = new MockHttpClient(
            function (string $method, string $url) use (&$requested): MockResponse {
                $requested[] = $url;

                return new MockResponse(count($requested) === 1 ? '{}' : $this->idfmBody());
            },
        );
        $client = $this->client($http);

        $visits = $client->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'idfm'),
            ['IDFM:22104', 'IDFM:22105'],
            [],
        );

        self::assertCount(2, $requested);
        self::assertStringContainsString('MonitoringRef=STIF%3AStopPoint%3AQ%3A22104%3A', $requested[0]);
        self::assertStringContainsString('MonitoringRef=STIF%3AStopPoint%3AQ%3A22105%3A', $requested[1]);
        self::assertCount(1, $visits);
    }

    #[Test]
    public function itTriesAtMostFourStopRefs(): void
    {
        $requested = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('{}');
        });
        $client = $this->client($http);

        $visits = $client->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'idfm'),
            ['a', 'b', 'c', 'd', 'e', 'f'],
            [],
        );

        self::assertSame([], $visits);
        self::assertCount(4, $requested);
    }

    #[Test]
    public function itCachesAFailedFetchForTheFailureTtl(): void
    {
        $calls = 0;
        $cache = new ArrayAdapter();
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 503]);
        });
        $client = $this->client($http, null, $cache);
        $selection = new RealtimeSelection(RealtimeProvider::SiriLite->value, 'idfm');

        self::assertSame([], $client->visits($selection, ['555'], []));
        self::assertSame([], $client->visits($selection, ['555'], []));
        self::assertSame(1, $calls);

        $url = 'https://1.1.1.1/idfm?' . http_build_query(['MonitoringRef' => 'STIF:StopPoint:Q:555:']);
        $expiry = $cache->getItem('siri_idfm_' . md5($url))->getMetadata()['expiry'] ?? null;
        self::assertIsNumeric($expiry);
        self::assertGreaterThan(time() + 50, (float) $expiry);
    }

    #[Test]
    public function itCapsEstimatedTimetableLineRequests(): void
    {
        $requested = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('{}');
        });
        $client = $this->client($http);
        $routes = array_map(static fn (int $index): string => 'route-' . $index, range(1, 10));

        $visits = $client->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'tcl'),
            ['999'],
            $routes,
        );

        self::assertSame([], $visits);
        self::assertCount(8, $requested);
    }

    #[Test]
    public function itFetchesTclEstimatedTimetablePerLineAndFiltersByStop(): void
    {
        $captured = null;
        $http = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured = ['url' => $url, 'headers' => $this->headerLines($options)];

                return new MockResponse($this->tclBody());
            },
        );
        $client = $this->client($http);

        $visits = $client->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'tcl'),
            ['999'],
            ['102'],
        );

        self::assertIsArray($captured);
        self::assertStringContainsString('LineRef=102', $captured['url']);
        self::assertContains(
            'Authorization: Basic ' . base64_encode('test-sytral-user:test-sytral-pass'),
            $captured['headers'],
        );
        self::assertCount(1, $visits);
        self::assertSame('TCL:Stop:999', $visits[0]->stopCode);
        self::assertSame('trip-1', $visits[0]->tripRef);
        self::assertSame(4, $visits[0]->stopSequence);
        self::assertSame('Part-Dieu', $visits[0]->destination);
    }

    #[Test]
    public function itFetchesCtsEstimatedTimetablePerLineAndFiltersByStop(): void
    {
        $captured = null;
        $http = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured = ['url' => $url, 'headers' => $this->headerLines($options)];

                return new MockResponse($this->ctsBody());
            },
        );
        $client = $this->client($http);

        $visits = $client->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'cts'),
            ['777'],
            ['A'],
        );

        self::assertIsArray($captured);
        self::assertStringContainsString('GetStopIdInsteadOfStopCode=true', $captured['url']);
        self::assertStringContainsString('LineRef=A', $captured['url']);
        self::assertContains('Authorization: Basic ' . base64_encode('test-cts-token:'), $captured['headers']);
        self::assertCount(1, $visits);
        self::assertSame('CTS:Stop:777', $visits[0]->stopCode);
        self::assertSame('cts-journey-1', $visits[0]->tripRef);
        self::assertSame(2, $visits[0]->stopSequence);
        self::assertSame('Lingolsheim', $visits[0]->destination);
    }

    #[Test]
    public function itKeepsExpectedOnlyCallsTheWayCtsSendsThem(): void
    {
        // CTS estimated-timetable calls carry expected times only: no aimed times and no order.
        $body = json_encode([
            'ServiceDelivery' => [
                'EstimatedTimetableDelivery' => [[
                    'EstimatedJourneyVersionFrame' => [[
                        'EstimatedVehicleJourney' => [[
                            'LineRef' => 'A',
                            'FramedVehicleJourneyRef' => ['DatedVehicleJourneySAERef' => '22406908-26SEPW-A-D-Samedi-00'],
                            'EstimatedCalls' => [
                                [
                                    'StopPointRef' => 'PASPO_01',
                                    'DestinationName' => 'Illkirch Graffenstaden',
                                    'ExpectedDepartureTime' => '2026-11-07T14:24:05+01:00',
                                    'ExpectedArrivalTime' => '2026-11-07T14:16:05+01:00',
                                ],
                            ],
                        ]],
                    ]],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
        $client = $this->client(new MockHttpClient(new MockResponse($body)));

        $visits = $client->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'cts'),
            ['PASPO_01'],
            ['A'],
        );

        self::assertCount(1, $visits);
        $visit = $visits[0];
        self::assertSame('A', $visit->lineCode);
        self::assertSame('PASPO_01', $visit->stopCode);
        self::assertSame('22406908-26SEPW-A-D-Samedi-00', $visit->tripRef);
        $expected = (new \DateTimeImmutable('2026-11-07T14:24:05+01:00'))->getTimestamp();
        self::assertSame($expected, $visit->expectedAt);
        self::assertSame($expected, $visit->aimedAt);
        self::assertNull($visit->stopSequence);
        self::assertSame('Illkirch Graffenstaden', $visit->destination);
    }

    #[Test]
    public function itMarksAVisitCancelledWhenTheJourneyIsCancelled(): void
    {
        $body = json_encode([
            'Siri' => [
                'ServiceDelivery' => [
                    'EstimatedTimetableDelivery' => [[
                        'EstimatedJourneyVersionFrame' => [[
                            'EstimatedVehicleJourney' => [[
                                'LineRef' => 'TCL:Line::T1:',
                                'Cancellation' => true,
                                'EstimatedCalls' => [[
                                    'StopPointRef' => 'TCL:Stop:999',
                                    'Order' => 4,
                                    'AimedDepartureTime' => '2026-11-06T13:20:00+01:00',
                                ]],
                            ]],
                        ]],
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $client = $this->client(new MockHttpClient(new MockResponse($body)));

        $visits = $client->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'tcl'),
            ['999'],
            ['102'],
        );

        self::assertCount(1, $visits);
        self::assertTrue($visits[0]->cancelled);
    }

    #[Test]
    public function itCachesVisitsPerUrl(): void
    {
        $calls = 0;
        $client = $this->client(new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse($this->idfmBody());
        }));
        $selection = new RealtimeSelection(RealtimeProvider::SiriLite->value, 'idfm');

        $client->visits($selection, ['555'], []);
        $client->visits($selection, ['555'], []);

        self::assertSame(1, $calls);
    }

    #[Test]
    public function itReturnsAnEmptyListForMalformedOrFailedResponses(): void
    {
        $selection = new RealtimeSelection(RealtimeProvider::SiriLite->value, 'idfm');

        self::assertSame([], $this->client(
            new MockHttpClient(new MockResponse('not json')),
        )->visits($selection, ['555'], []));
        self::assertSame([], $this->client(new MockHttpClient(
            static fn (): MockResponse => new MockResponse('', ['http_code' => 503]),
        ))->visits($selection, ['555'], []));
    }

    #[Test]
    public function itReturnsAnEmptyListForAnUnknownUnavailableOrNonSiriSelection(): void
    {
        $http = new MockHttpClient(fn (): MockResponse => new MockResponse($this->idfmBody()));
        $unavailable = TestSiriProfiles::registry(TestSiriProfiles::withUnavailableProfile());

        self::assertSame([], $this->client($http)->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'unknown'),
            ['555'],
            [],
        ));
        self::assertSame([], $this->client($http, $unavailable)->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'offline'),
            ['555'],
            [],
        ));
        self::assertSame([], $this->client($http)->visits(
            RealtimeSelection::fromParts(null, null, 'https://8.8.8.8/rt'),
            ['555'],
            [],
        ));
        self::assertSame(0, $http->getRequestsCount());
    }

    #[Test]
    public function itLogsWhenTheRequestedProfileIsUnknownOrHasMissingCredentials(): void
    {
        $records = new RecordingLogger();
        $http = new MockHttpClient(fn (): MockResponse => new MockResponse($this->idfmBody()));
        $unavailable = TestSiriProfiles::registry(TestSiriProfiles::withUnavailableProfile());
        $client = new SiriLiteClient(
            new OutboundHttpFetcher($http, new OutboundUrlGuard(), $records),
            $unavailable,
            new ArrayAdapter(),
            $records,
        );

        self::assertSame([], $client->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'unknown'),
            ['555'],
            [],
        ));
        self::assertSame([], $client->visits(
            new RealtimeSelection(RealtimeProvider::SiriLite->value, 'offline'),
            ['555'],
            [],
        ));

        self::assertSame([
            'SIRI visits skipped: no usable profile "unknown" (unknown profile key).',
            'SIRI visits skipped: no usable profile "offline" (credentials missing or incomplete).',
        ], $records->messages());
        self::assertSame(0, $http->getRequestsCount());
    }

    private function client(
        MockHttpClient $http,
        ?SiriProfileRegistry $profiles = null,
        ?ArrayAdapter $cache = null,
    ): SiriLiteClient {
        return new SiriLiteClient(
            new OutboundHttpFetcher($http, new OutboundUrlGuard(), new NullLogger()),
            $profiles ?? TestSiriProfiles::registry(),
            $cache ?? new ArrayAdapter(),
            new NullLogger(),
        );
    }

    /**
     * @param array<mixed> $options
     *
     * @return list<string>
     */
    private function headerLines(array $options): array
    {
        $lines = [];
        foreach (is_array($options['headers'] ?? null) ? $options['headers'] : [] as $line) {
            if (is_string($line)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function idfmBody(): string
    {
        return json_encode([
            'Siri' => [
                'ServiceDelivery' => [
                    'StopMonitoringDelivery' => [[
                        'MonitoredStopVisit' => [[
                            'MonitoredVehicleJourney' => [
                                'LineRef' => ['value' => 'IDFM:C01624'],
                                'FramedVehicleJourneyRef' => ['DatedVehicleJourneyRef' => 'STIF:VehicleJourney:123'],
                                'DestinationName' => [['value' => 'Gare de Lyon']],
                                'MonitoredCall' => [
                                    'StopPointRef' => ['value' => 'STIF:StopPoint:Q:22104:'],
                                    'Order' => 3,
                                    'AimedDepartureTime' => '2026-11-06T13:00:00+01:00',
                                    'ExpectedDepartureTime' => '2026-11-06T13:03:00+01:00',
                                ],
                            ],
                        ]],
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function tclBody(): string
    {
        return json_encode([
            'ServiceDelivery' => [
                'EstimatedTimetableDelivery' => [
                    'EstimatedTimetableVersionFrame' => [
                        'EstimatedVehicleJourney' => [
                            'LineRef' => 'TCL:Line::T1:',
                            'DatedVehicleJourneyRef' => 'trip-1',
                            'DestinationRef' => 'TCL:Stop:dest',
                            'EstimatedCalls' => ['EstimatedCall' => [
                                [
                                    'StopPointRef' => 'TCL:Stop:other',
                                    'Order' => 3,
                                    'AimedDepartureTime' => '2026-11-06T13:10:00+01:00',
                                    'ExpectedDepartureTime' => '2026-11-06T13:12:00+01:00',
                                ],
                                [
                                    'StopPointRef' => 'TCL:Stop:999',
                                    'Order' => 4,
                                    'DestinationDisplay' => ['value' => 'Part-Dieu'],
                                    'AimedDepartureTime' => '2026-11-06T13:20:00+01:00',
                                    'ExpectedDepartureTime' => '2026-11-06T13:22:00+01:00',
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function ctsBody(): string
    {
        return json_encode([
            'ServiceDelivery' => [
                'EstimatedTimetableDelivery' => [
                    'EstimatedJourneyVersionFrame' => [
                        'EstimatedVehicleJourney' => [
                            'LineRef' => ['value' => 'CTS:Line::A:'],
                            'FramedVehicleJourneyRef' => ['DatedVehicleJourneySAERef' => 'cts-journey-1'],
                            'DestinationName' => 'Lingolsheim',
                            'EstimatedCalls' => [
                                [
                                    'StopPointRef' => ['value' => 'CTS:Stop:777'],
                                    'Order' => 2,
                                    'AimedDepartureTime' => '2026-11-06T13:20:00+01:00',
                                    'ExpectedDepartureTime' => '2026-11-06T13:21:00+01:00',
                                ],
                                [
                                    'StopPointRef' => ['value' => 'CTS:Stop:888'],
                                    'AimedDepartureTime' => '2026-11-06T13:25:00+01:00',
                                    'ExpectedDepartureTime' => '2026-11-06T13:26:00+01:00',
                                    'DepartureStatus' => 'cancelled',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
