<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\Test;

final class TransitCatalogApiTest extends ApiTestCase
{
    private string $catalogFile = '';
    private string $sizesFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $catalogFile = self::getContainer()->getParameter('app.transit_catalog_file');
        self::assertIsString($catalogFile);
        $directory = dirname($catalogFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }
        // A copy inside the cache keeps the static fixture read-only and the snapshot fresh.
        copy(dirname(__DIR__) . '/Fixtures/transit-catalog.csv', $catalogFile);
        $this->catalogFile = $catalogFile;

        $sizesFile = self::getContainer()->getParameter('app.transit_catalog_sizes_file');
        self::assertIsString($sizesFile);
        $this->seedSizes($sizesFile);
        $this->sizesFile = $sizesFile;
    }

    protected function tearDown(): void
    {
        if ($this->catalogFile !== '') {
            @unlink($this->catalogFile);
        }
        if ($this->sizesFile !== '') {
            @unlink($this->sizesFile);
            @unlink($this->sizesFile . '.lock');
        }
        parent::tearDown();
    }

    #[Test]
    public function itReturnsRankedCatalogSuggestions(): void
    {
        $client = static::createClient();

        /** @var list<array<string, mixed>> $data */
        $data = $client->request('GET', '/api/transit/catalog', $this->jsonAuth() + [
            'query' => ['bbox' => '48.9757,6.0031,49.2037,6.3245', 'q' => 'Metz'],
        ])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(
            [
                'id',
                'provider',
                'name',
                'municipality',
                'countryCode',
                'licenseUrl',
                'downloadUrl',
                'fallbackUrl',
                'realtimeUrl',
                'live',
                'scope',
                'sizeBytes',
            ],
            array_keys($data[0]),
        );
        self::assertSame(['tdg-80725', 'tdg-80427'], array_column($data, 'id'));
        self::assertSame('LeMet', $data[0]['provider']);
        self::assertSame('Metz', $data[0]['municipality']);
        self::assertSame('FR', $data[0]['countryCode']);
        self::assertSame('https://files.example.com/lemet-latest.zip', $data[0]['downloadUrl']);
        self::assertSame('https://files.example.com/lemet.zip', $data[0]['fallbackUrl']);
        self::assertSame('https://rt.example.com/lemet-tu.pb', $data[0]['realtimeUrl']);
        self::assertTrue($data[0]['live']);
        self::assertSame('local', $data[0]['scope']);
        self::assertSame('https://example.com/lemet-license', $data[0]['licenseUrl']);
        self::assertSame(1000, $data[0]['sizeBytes']);
    }

    #[Test]
    public function itFiltersByQueryText(): void
    {
        $client = static::createClient();

        /** @var list<array<string, mixed>> $data */
        $data = $client->request('GET', '/api/transit/catalog', $this->jsonAuth() + [
            'query' => ['bbox' => '42,-4,51.5,6', 'q' => 'SNCF'],
        ])->toArray();

        self::assertContains('tdg-83582', array_column($data, 'id'));
    }

    #[Test]
    public function itRejectsAnInvalidBoundingBox(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/transit/catalog', self::AUTH + [
            'query' => ['bbox' => '48.9,6.0,49.2'],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'transit_catalog.invalid_bbox']);
    }

    #[Test]
    public function itRejectsABoundingBoxWiderThanTenDegrees(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/transit/catalog', self::AUTH + [
            'query' => ['bbox' => '40,-5,55,8'],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'transit_catalog.invalid_bbox']);
    }

    #[Test]
    public function itRejectsAnInvalidQuery(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/transit/catalog', self::AUTH + [
            'query' => ['bbox' => '48.9757,6.0031,49.2037,6.3245', 'limit' => '0'],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'transit_catalog.invalid_query']);
    }

    #[Test]
    public function missingApiKeyReturns401(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/transit/catalog', [
            'query' => ['bbox' => '48.9757,6.0031,49.2037,6.3245'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    private function seedSizes(string $sizesFile): void
    {
        $handle = fopen(dirname(__DIR__) . '/Fixtures/transit-catalog.csv', 'rb');
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
        file_put_contents($sizesFile, json_encode($sizes, JSON_THROW_ON_ERROR));
    }

    /**
     * The Android client sends Accept: application/json and expects a bare array, not hydra:member.
     *
     * @return array{headers: array<string, string>}
     */
    private function jsonAuth(): array
    {
        return ['headers' => self::AUTH['headers'] + ['Accept' => 'application/json']];
    }
}
