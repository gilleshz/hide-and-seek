<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AlertSeverity;
use App\Service\SiriAlertsParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SiriAlertsParserTest extends TestCase
{
    #[Test]
    public function itParsesTheCapturedCtsGeneralMessageSample(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/cts-general-message.sample.json');

        $alerts = SiriAlertsParser::parse($body, 'siri_lite:cts');

        self::assertCount(18, $alerts);

        $first = $alerts[0];
        self::assertStringStartsWith('d5413093215860660458-2-', $first->id);
        self::assertSame('PlannedDisruption', $first->channel);
        self::assertSame(AlertSeverity::Warning, $first->severity);
        self::assertSame('Manifestation à Strasbourg', $first->title);
        self::assertSame('siri_lite:cts', $first->source);
        self::assertSame([], $first->routeIds);
        self::assertSame([], $first->stopIds);
        self::assertContains('30', $first->lineRefs);
        self::assertContains('G', $first->lineRefs);
        self::assertCount(11, $first->lineRefs);
        self::assertSame(
            (new \DateTimeImmutable('2026-11-07T13:30:00+01:00'))->getTimestamp(),
            $first->validFrom,
        );
        self::assertSame(
            (new \DateTimeImmutable('2100-01-01T01:00:00+01:00'))->getTimestamp(),
            $first->validUntil,
        );

        self::assertStringStartsWith("En raison d'une manifestation", $first->body);
        self::assertStringNotContainsString('<', $first->body);
        self::assertStringNotContainsString('&nbsp;', $first->body);
        self::assertStringNotContainsString("\u{00A0}", $first->body);
        self::assertStringContainsString('Ligne 30', $first->body);
        self::assertStringContainsString('- ', $first->body);
        self::assertLessThanOrEqual(4000, mb_strlen($first->body));

        $works = $alerts[1];
        self::assertSame('Works', $works->channel);
        self::assertSame(AlertSeverity::Warning, $works->severity);
        self::assertSame(['70'], $works->lineRefs);
        self::assertSame('Arrêt Karine non desservi et arrêt Jacqueline déplacé', $works->title);
    }

    #[Test]
    public function itAcceptsASingleLineRefAndFallsBackToTheFirstMessageForTheTitle(): void
    {
        $body = (string) json_encode([
            'Siri' => [
                'ServiceDelivery' => [
                    'GeneralMessageDelivery' => [[
                        'InfoMessage' => [[
                            'InfoMessageIdentifier' => 'msg-1',
                            'InfoChannelRef' => 'Information',
                            'ValidUntilTime' => '2026-12-01T02:00:00+01:00',
                            'Content' => [
                                'ImpactedLineRef' => 'SYTRAL:Line::C1:',
                                'Message' => [
                                    [
                                        'MessageZoneRef' => 'details',
                                        'MessageText' => [['Value' => '<p>Body</p>', 'Lang' => 'FR']],
                                    ],
                                    [
                                        'MessageZoneRef' => 'title',
                                        'MessageText' => [
                                            ['Value' => 'Titre EN', 'Lang' => 'EN'],
                                            ['Value' => 'Titre FR', 'Lang' => 'FR'],
                                        ],
                                    ],
                                ],
                            ],
                        ]],
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $alerts = SiriAlertsParser::parse($body, 'siri_lite:tcl');

        self::assertCount(1, $alerts);
        $alert = $alerts[0];
        self::assertSame('msg-1', $alert->id);
        self::assertSame('Information', $alert->channel);
        self::assertSame(AlertSeverity::Info, $alert->severity);
        self::assertSame('Titre FR', $alert->title);
        self::assertSame('Body', $alert->body);
        self::assertSame(['SYTRAL:Line::C1:'], $alert->lineRefs);
        self::assertNull($alert->validFrom);
        self::assertSame((new \DateTimeImmutable('2026-12-01T02:00:00+01:00'))->getTimestamp(), $alert->validUntil);
    }

    #[Test]
    public function itMapsTheSeverityFieldWhenPresentAndSkipsMessagesWithoutAnId(): void
    {
        $body = (string) json_encode([
            'ServiceDelivery' => [
                'GeneralMessageDelivery' => [
                    ['InfoMessage' => [
                        [
                            'InfoMessageIdentifier' => 'sev-1',
                            'InfoChannelRef' => 'x',
                            'Severity' => 'critical',
                            'Content' => [],
                        ],
                        ['InfoChannelRef' => 'x', 'Content' => []],
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $alerts = SiriAlertsParser::parse($body, 'siri_lite:cts');

        self::assertCount(1, $alerts);
        self::assertSame(AlertSeverity::Severe, $alerts[0]->severity);
        self::assertSame('', $alerts[0]->title);
        self::assertSame('', $alerts[0]->body);
        self::assertSame([], $alerts[0]->lineRefs);
    }

    #[Test]
    public function itReturnsAnEmptyListForUnparsableBodies(): void
    {
        self::assertSame([], SiriAlertsParser::parse('not json', 'siri_lite:cts'));
        self::assertSame([], SiriAlertsParser::parse('{}', 'siri_lite:cts'));
    }
}
