<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Get;
use App\Service\MapStyleService;
use App\Service\SiriProfileRegistry;
use App\State\ClientConfigProvider;
use App\Tests\Support\TestSiriProfiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class ClientConfigProviderTest extends TestCase
{
    #[Test]
    public function itMapsTheAvailableSiriProfiles(): void
    {
        $resource = $this->provider()->provide(new Get());

        self::assertSame([
            ['key' => 'idfm', 'label' => 'Île-de-France Mobilités'],
            ['key' => 'cts', 'label' => 'CTS Strasbourg'],
            ['key' => 'tcl', 'label' => 'TCL Lyon'],
        ], $resource->siriProfiles);
    }

    #[Test]
    public function itOmitsAProfileWithBlankCredentials(): void
    {
        $resource = $this->provider(
            TestSiriProfiles::registry(TestSiriProfiles::withUnavailableProfile()),
        )->provide(new Get());

        self::assertSame([
            ['key' => 'idfm', 'label' => 'Île-de-France Mobilités'],
            ['key' => 'cts', 'label' => 'CTS Strasbourg'],
            ['key' => 'tcl', 'label' => 'TCL Lyon'],
        ], $resource->siriProfiles);
    }

    private function provider(?SiriProfileRegistry $profiles = null): ClientConfigProvider
    {
        return new ClientConfigProvider(
            new MapStyleService(new MockHttpClient([]), new ArrayAdapter(), []),
            $profiles ?? TestSiriProfiles::registry(),
        );
    }
}
