<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RefreshTransitCatalogCommand;
use App\Service\OutboundHttpFetcher;
use App\Service\OutboundUrlGuard;
use App\Service\TransitCatalogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RefreshTransitCatalogCommandTest extends TestCase
{
    private string $workDir;
    private string $catalogFile;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/transit-catalog-command-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o755, true);
        $this->catalogFile = $this->workDir . '/feeds.csv';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);
    }

    #[Test]
    public function itWritesTheDownloadedCatalogAndReportsTheRowCount(): void
    {
        $tester = new CommandTester($this->command(new MockHttpClient([new MockResponse($this->catalogCsv())])));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertFileExists($this->catalogFile);
        self::assertStringContainsString('2 transit catalog row(s)', $tester->getDisplay());
        self::assertStringContainsString($this->catalogFile, $tester->getDisplay());
    }

    #[Test]
    public function itKeepsThePreviousSnapshotWhenTheDownloadFails(): void
    {
        file_put_contents($this->catalogFile, 'old snapshot');
        $tester = new CommandTester($this->command(new MockHttpClient([new MockResponse('', ['http_code' => 500])])));

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('old snapshot', (string) file_get_contents($this->catalogFile));
        self::assertFileExists($this->catalogFile . '.failed');
    }

    #[Test]
    public function itKeepsThePreviousSnapshotWhenTheHeaderIsInvalid(): void
    {
        file_put_contents($this->catalogFile, 'old snapshot');
        $tester = new CommandTester($this->command(new MockHttpClient([new MockResponse('id,data_type')])));

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('old snapshot', (string) file_get_contents($this->catalogFile));
    }

    private function command(MockHttpClient $client): RefreshTransitCatalogCommand
    {
        $fetcher = new OutboundHttpFetcher($client, new OutboundUrlGuard(), new NullLogger());
        $service = new TransitCatalogService(
            $fetcher,
            new NullLogger(),
            $this->catalogFile,
            'https://8.8.8.8/feeds_v2.csv',
            $this->workDir . '/sizes.json',
        );

        return new RefreshTransitCatalogCommand($service, $this->catalogFile, new NullLogger());
    }

    private function catalogCsv(): string
    {
        $fixture = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/transit-catalog.csv');
        $header = (string) strtok($fixture, "\n");
        $emptyRow = str_repeat(',', 26);

        return $header . "\n" . $emptyRow . "\n" . $emptyRow . "\n";
    }
}
