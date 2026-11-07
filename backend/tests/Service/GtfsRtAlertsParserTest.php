<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AlertSeverity;
use App\Service\GtfsRtAlertsParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class GtfsRtAlertsParserTest extends TestCase
{
    private const string PYTHON = '/opt/osm2gtfs/bin/python';

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/gtfs-rt-alerts-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
        parent::tearDown();
    }

    #[Test]
    public function itMapsHandBuiltFeedMessageAlertsOntoTrafficAlerts(): void
    {
        $decoded = $this->decodeFeed($this->feedCode());
        self::assertSame('alert', $decoded['type']);

        $alerts = GtfsRtAlertsParser::parse($decoded);

        self::assertCount(2, $alerts);

        $severe = $alerts[0];
        self::assertSame('alert-1', $severe->id);
        self::assertSame('strike', $severe->channel);
        self::assertSame(AlertSeverity::Severe, $severe->severity);
        self::assertSame('Titre FR', $severe->title);
        self::assertSame('Corps FR', $severe->body);
        self::assertSame(['R1'], $severe->routeIds);
        self::assertSame(['S1'], $severe->stopIds);
        self::assertSame([], $severe->lineRefs);
        self::assertSame(1_800_000_000, $severe->validFrom);
        self::assertSame(1_800_003_600, $severe->validUntil);
        self::assertSame('gtfs_rt', $severe->source);

        $warning = $alerts[1];
        self::assertSame('alert-2', $warning->id);
        self::assertSame('construction', $warning->channel);
        self::assertSame(AlertSeverity::Warning, $warning->severity);
        self::assertSame('English only title', $warning->title);
        self::assertSame('English only body', $warning->body);
        self::assertSame(['R2'], $warning->routeIds);
        self::assertSame([], $warning->stopIds);
        self::assertNull($warning->validFrom);
        self::assertNull($warning->validUntil);
    }

    #[Test]
    public function itAggregatesMultipleActivePeriodsIntoMinStartAndMaxEnd(): void
    {
        $decoded = $this->decodeFeed(<<<'PY'
            import sys
            from google.transit import gtfs_realtime_pb2 as pb
            feed = pb.FeedMessage()
            feed.header.gtfs_realtime_version = "2.0"
            feed.header.timestamp = 1800000000
            entity = feed.entity.add()
            entity.id = "alert-multi"
            alert = entity.alert
            alert.cause = pb.Alert.Cause.Value("STRIKE")
            alert.effect = pb.Alert.Effect.Value("NO_SERVICE")
            alert.header_text.translation.add(language="en", text="Title")
            alert.description_text.translation.add(language="en", text="Body")
            first = alert.active_period.add()
            first.start = 1800000100
            first.end = 1800000200
            second = alert.active_period.add()
            second.start = 1800000050
            third = alert.active_period.add()
            third.end = 1800000900
            open(sys.argv[1], "wb").write(feed.SerializeToString())
            PY);

        $alerts = GtfsRtAlertsParser::parse($decoded);

        self::assertCount(1, $alerts);
        self::assertSame('alert-multi', $alerts[0]->id);
        self::assertSame(1_800_000_050, $alerts[0]->validFrom);
        self::assertSame(1_800_000_900, $alerts[0]->validUntil);
    }

    #[Test]
    public function itReturnsAnEmptyListWhenTheFeedCarriesNoAlerts(): void
    {
        self::assertSame([], GtfsRtAlertsParser::parse([]));
        self::assertSame([], GtfsRtAlertsParser::parse(['alerts' => 'nope']));
        self::assertSame([], GtfsRtAlertsParser::parse(['alerts' => [['id' => '  ']]]));
    }

    /**
     * @return array<mixed>
     */
    private function decodeFeed(string $generatorCode): array
    {
        $decoder = dirname(__DIR__, 2) . '/bin/gtfs_rt_decode.py';
        if (!is_file(self::PYTHON) || !is_file($decoder)) {
            self::markTestSkipped('The GTFS-RT Python runtime is not available.');
        }
        $probe = new Process([self::PYTHON, '-c', 'import google.transit.gtfs_realtime_pb2']);
        $probe->run();
        if (!$probe->isSuccessful()) {
            self::markTestSkipped('gtfs-realtime-bindings is not installed.');
        }

        $path = $this->workDir . '/feed.pb';
        $generator = new Process([self::PYTHON, '-c', $generatorCode, $path]);
        $generator->run();
        self::assertTrue($generator->isSuccessful(), $generator->getErrorOutput());
        $bytes = (string) file_get_contents($path);

        $process = new Process([self::PYTHON, $decoder], null, null, $bytes, 5);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function feedCode(): string
    {
        return <<<'PY'
            import sys
            from google.transit import gtfs_realtime_pb2 as pb
            feed = pb.FeedMessage()
            feed.header.gtfs_realtime_version = "2.0"
            feed.header.timestamp = 1800000000
            first = feed.entity.add()
            first.id = "alert-1"
            alert = first.alert
            alert.cause = pb.Alert.Cause.Value("STRIKE")
            alert.effect = pb.Alert.Effect.Value("NO_SERVICE")
            alert.header_text.translation.add(language="en", text="English title")
            alert.header_text.translation.add(language="fr", text="Titre FR")
            alert.description_text.translation.add(language="fr", text="Corps FR")
            informed = alert.informed_entity.add()
            informed.route_id = "R1"
            informed.stop_id = "S1"
            period = alert.active_period.add()
            period.start = 1800000000
            period.end = 1800003600
            second = feed.entity.add()
            second.id = "alert-2"
            alert2 = second.alert
            alert2.cause = pb.Alert.Cause.Value("CONSTRUCTION")
            alert2.effect = pb.Alert.Effect.Value("SIGNIFICANT_DELAYS")
            alert2.header_text.translation.add(language="en", text="English only title")
            alert2.description_text.translation.add(language="en", text="English only body")
            alert2.informed_entity.add().route_id = "R2"
            open(sys.argv[1], "wb").write(feed.SerializeToString())
            PY;
    }
}
