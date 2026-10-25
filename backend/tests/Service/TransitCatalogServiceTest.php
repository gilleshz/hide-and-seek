<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\TransitCatalogSuggestion;
use App\Service\OutboundHttpFetcher;
use App\Service\OutboundUrlGuard;
use App\Service\TransitCatalogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TransitCatalogServiceTest extends TestCase
{
    private string $workDir;
    private string $catalogFile;
    private string $sizesFile;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/transit-catalog-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o755, true);
        $this->catalogFile = $this->workDir . '/feeds.csv';
        $this->sizesFile = $this->workDir . '/sizes.json';
        $this->seedSizes();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);
    }

    #[Test]
    public function itRanksTheLocalFeedBeforeTheRegionalOneAndHidesNationalFeeds(): void
    {
        $this->copyFixture();

        $results = $this->service()->search(48.9757, 6.0031, 49.2037, 6.3245, 'Metz');

        self::assertSame(['tdg-80725', 'tdg-80427'], self::ids($results));
        $lemet = $results[0];
        self::assertSame('LeMet', $lemet->provider);
        self::assertSame('Metz', $lemet->municipality);
        self::assertSame('FR', $lemet->countryCode);
        self::assertSame('local', $lemet->scope);
        self::assertSame('https://files.example.com/lemet-latest.zip', $lemet->downloadUrl);
        self::assertSame('https://files.example.com/lemet.zip', $lemet->fallbackUrl);
        self::assertSame('https://example.com/lemet-license', $lemet->licenseUrl);
        self::assertSame('https://rt.example.com/lemet-tu.pb', $lemet->realtimeUrl);
        self::assertTrue($lemet->live);
        self::assertSame(1000, $lemet->sizeBytes);
        self::assertSame('regional', $results[1]->scope);
        self::assertSame('https://rt.example.com/fluo-tu.pb', $results[1]->realtimeUrl);
    }

    #[Test]
    public function itExcludesDeprecatedAuthenticatedUnofficialRedirectedAndInvalidRows(): void
    {
        $this->copyFixture();

        $ids = self::ids($this->service()->search(48.95, 5.95, 49.25, 6.35, ''));

        self::assertContains('tdg-80725', $ids);
        foreach (['mdb-1298', 'tdg-auth', 'tdg-nonofficial', 'tdg-redirect', 'tdg-badbbox'] as $excluded) {
            self::assertNotContains($excluded, $ids);
        }
    }

    #[Test]
    public function itLeavesTheFallbackEmptyForADirectOnlyFeed(): void
    {
        $this->copyFixture();

        $results = $this->service()->search(49.30, 6.10, 49.45, 6.25, '');
        $directOnly = array_find(
            $results,
            static fn (TransitCatalogSuggestion $item): bool => $item->id === 'tdg-directonly',
        );

        self::assertNotNull($directOnly);
        self::assertSame('https://files.example.com/direct.zip', $directOnly->downloadUrl);
        self::assertNull($directOnly->fallbackUrl);
        self::assertNull($directOnly->realtimeUrl);
        self::assertFalse($directOnly->live);
    }

    #[Test]
    public function itReturnsNoFeedWhenTheViewportOverlapsNone(): void
    {
        $this->copyFixture();

        self::assertSame([], $this->service()->search(0.0, 0.0, 0.1, 0.1, ''));
    }

    #[Test]
    public function itStillMatchesAFeedByNameOutsideTheViewport(): void
    {
        $this->copyFixture();

        $results = $this->service()->search(0.0, 0.0, 0.1, 0.1, 'SNCF');

        self::assertSame(['tdg-83582'], self::ids($results));
    }

    #[Test]
    public function itDoesNotAdvertiseAnHttpRealtimeUrl(): void
    {
        $header = explode("\n", $this->fixture(), 2)[0];
        $static = $this->csvRow([
            'id' => 'http-rt',
            'data_type' => 'gtfs',
            'location.country_code' => 'FR',
            'location.municipality' => 'Metz',
            'provider' => 'HTTP RT',
            'name' => 'HTTP RT',
            'is_official' => 'True',
            'urls.direct_download' => 'https://files.example.com/http-rt.zip',
            'status' => 'active',
            'location.bounding_box.minimum_latitude' => '49.0',
            'location.bounding_box.maximum_latitude' => '49.1',
            'location.bounding_box.minimum_longitude' => '6.0',
            'location.bounding_box.maximum_longitude' => '6.1',
        ]);
        $realtime = $this->csvRow([
            'id' => 'http-rt-tu',
            'data_type' => 'gtfs_rt',
            'entity_type' => 'tu',
            'static_reference' => 'http-rt',
            'urls.direct_download' => 'http://rt.example.com/tu.pb',
            'urls.authentication_type' => '0',
            'status' => 'active',
        ]);
        file_put_contents($this->catalogFile, $header . "\n" . $static . "\n" . $realtime . "\n");

        $results = $this->service()->search(49.0, 6.0, 49.1, 6.1, '');

        self::assertSame(['http-rt'], self::ids($results));
        self::assertNull($results[0]->realtimeUrl);
        self::assertFalse($results[0]->live);
    }

    #[Test]
    public function itAcceptsAnUpstreamColumnAddedToTheSnapshotHeader(): void
    {
        [$header, $body] = explode("\n", $this->fixture(), 2);
        $client = new MockHttpClient([new MockResponse($header . ',upstream_extra' . "\n" . $body)]);

        $results = $this->service($client)->search(48.9757, 6.0031, 49.2037, 6.3245, 'Metz');

        self::assertSame('tdg-80725', $results[0]->id);
    }

    #[Test]
    public function itBacksOffAfterAFailedSnapshotDownload(): void
    {
        $failing = new MockHttpClient([new MockResponse('', ['http_code' => 500])]);
        self::assertSame([], $this->service($failing)->search(48.9757, 6.0031, 49.2037, 6.3245, 'Metz'));

        $second = new MockHttpClient([new MockResponse($this->fixture())]);
        self::assertSame([], $this->service($second)->search(48.9757, 6.0031, 49.2037, 6.3245, 'Metz'));
        self::assertSame(0, $second->getRequestsCount());
        self::assertFileExists($this->catalogFile . '.failed');
    }

    #[Test]
    public function itServesTheStaleSnapshotWhenARefreshFails(): void
    {
        $this->copyFixture();
        touch($this->catalogFile, time() - 2 * 86400);
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 500])]);

        $results = $this->service($client)->search(48.9757, 6.0031, 49.2037, 6.3245, 'Metz');

        self::assertSame('tdg-80725', $results[0]->id);
    }

    #[Test]
    public function itReturnsAnEmptyListWhenNoSnapshotExistsAndTheRefreshFails(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 500])]);

        self::assertSame([], $this->service($client)->search(48.9757, 6.0031, 49.2037, 6.3245, 'Metz'));
    }

    #[Test]
    public function itDownloadsTheSnapshotOnFirstUse(): void
    {
        $client = new MockHttpClient([new MockResponse($this->fixture())]);

        $results = $this->service($client)->search(48.9757, 6.0031, 49.2037, 6.3245, 'Metz');

        self::assertFileExists($this->catalogFile);
        self::assertSame('tdg-80725', $results[0]->id);
    }

    #[Test]
    public function itUsesTheCachedSizeWithoutAHead(): void
    {
        $this->copyFixture();
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            throw new \RuntimeException('No request expected.');
        });

        $results = $this->service($client)->search(48.9757, 6.0031, 49.2037, 6.3245, 'Metz');

        self::assertSame(0, $requests);
        self::assertSame(1000, $results[0]->sizeBytes);
    }

    #[Test]
    public function itHeadsAndCachesAMissingSize(): void
    {
        $this->writeSizeCatalogRow();
        file_put_contents($this->sizesFile, '{}');
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('HEAD', $method);
            self::assertSame('https://8.8.8.8/feed.zip', $url);

            return new MockResponse('', ['response_headers' => ['Content-Length' => '1234']]);
        });

        $results = $this->service($client)->search(49.0, 6.0, 49.1, 6.1, '');

        self::assertSame('size-head', $results[0]->id);
        self::assertSame(1234, $results[0]->sizeBytes);
        self::assertSame(1234, $this->cacheEntry('https://8.8.8.8/feed.zip')['size'] ?? null);
    }

    #[Test]
    public function itKeepsTheSuggestionWhenTheHeadFails(): void
    {
        $this->writeSizeCatalogRow();
        file_put_contents($this->sizesFile, '{}');
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));

        $results = $this->service($client)->search(49.0, 6.0, 49.1, 6.1, '');

        self::assertSame('size-head', $results[0]->id);
        self::assertNull($results[0]->sizeBytes);
        $entry = $this->cacheEntry('https://8.8.8.8/feed.zip');
        self::assertArrayHasKey('size', $entry);
        self::assertNull($entry['size'] ?? null);
    }

    private function service(?MockHttpClient $client = null): TransitCatalogService
    {
        $fetcher = new OutboundHttpFetcher($client ?? new MockHttpClient([]), new OutboundUrlGuard(), new NullLogger());

        return new TransitCatalogService(
            $fetcher,
            new NullLogger(),
            $this->catalogFile,
            'https://8.8.8.8/feeds_v2.csv',
            $this->sizesFile,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheEntry(string $url): array
    {
        $cache = json_decode((string) file_get_contents($this->sizesFile), true);
        $entry = is_array($cache) ? ($cache[$url] ?? null) : null;
        if (!is_array($entry)) {
            self::fail('Expected a sizes cache entry for ' . $url . '.');
        }

        $result = [];
        foreach ($entry as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function seedSizes(): void
    {
        $handle = fopen($this->fixturePath(), 'rb');
        if ($handle === false) {
            return;
        }

        $sizes = [];
        try {
            $header = fgetcsv($handle, escape: '');
            $columns = is_array($header) ? array_filter($header, 'is_string') : [];
            $map = array_flip($columns);
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                foreach (['urls.latest', 'urls.direct_download'] as $column) {
                    $index = $map[$column] ?? null;
                    $url = is_int($index) ? trim($row[$index] ?? '') : '';
                    if ($url !== '') {
                        $sizes[$url] = ['size' => 1000, 'at' => time()];
                    }
                }
            }
        } finally {
            fclose($handle);
        }
        file_put_contents($this->sizesFile, json_encode($sizes, JSON_THROW_ON_ERROR));
    }

    private function writeSizeCatalogRow(): void
    {
        $header = explode("\n", $this->fixture(), 2)[0];
        $row = $this->csvRow([
            'id' => 'size-head',
            'data_type' => 'gtfs',
            'location.country_code' => 'FR',
            'location.municipality' => 'Metz',
            'provider' => 'Size Head',
            'name' => 'Size Head',
            'is_official' => 'True',
            'urls.direct_download' => 'https://8.8.8.8/feed.zip',
            'status' => 'active',
            'location.bounding_box.minimum_latitude' => '49.0',
            'location.bounding_box.maximum_latitude' => '49.1',
            'location.bounding_box.minimum_longitude' => '6.0',
            'location.bounding_box.maximum_longitude' => '6.1',
        ]);
        file_put_contents($this->catalogFile, $header . "\n" . $row . "\n");
    }

    private function copyFixture(): void
    {
        copy($this->fixturePath(), $this->catalogFile);
    }

    private function fixture(): string
    {
        return (string) file_get_contents($this->fixturePath());
    }

    private function fixturePath(): string
    {
        return dirname(__DIR__) . '/Fixtures/transit-catalog.csv';
    }

    /**
     * @param array<string, string> $values
     */
    private function csvRow(array $values): string
    {
        $columns = explode(',', explode("\n", $this->fixture(), 2)[0]);

        return implode(',', array_map(static fn (string $column): string => $values[$column] ?? '', $columns));
    }

    /**
     * @param list<TransitCatalogSuggestion> $suggestions
     *
     * @return list<string>
     */
    private static function ids(array $suggestions): array
    {
        return array_map(static fn (TransitCatalogSuggestion $suggestion): string => $suggestion->id, $suggestions);
    }
}
