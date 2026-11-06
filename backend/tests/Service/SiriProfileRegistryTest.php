<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SiriProfileRegistry;
use App\Service\SiriRef;
use App\Tests\Support\TestSiriProfiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SiriProfileRegistryTest extends TestCase
{
    #[Test]
    public function itListsOnlyProfilesWithCredentials(): void
    {
        $config = $this->blankAuth(TestSiriProfiles::config(), 'idfm', 'value');
        $config = $this->blankAuth($config, 'cts', 'user');

        $available = new SiriProfileRegistry($config)->available();

        self::assertSame(['tcl'], array_column($available, 'key'));
    }

    #[Test]
    public function aBasicProfileWithAnEmptyPasswordStaysAvailable(): void
    {
        $available = new SiriProfileRegistry(TestSiriProfiles::config())->available();

        self::assertSame(['idfm', 'cts', 'tcl'], array_column($available, 'key'));
    }

    #[Test]
    public function aRequiredPasswordMakesAProfileUnavailable(): void
    {
        $config = $this->blankAuth(TestSiriProfiles::config(), 'tcl', 'password');

        $available = new SiriProfileRegistry($config)->available();

        self::assertSame(['idfm', 'cts'], array_column($available, 'key'));
    }

    #[Test]
    public function itExpandsStopAndLineTemplates(): void
    {
        $profiles = new SiriProfileRegistry(TestSiriProfiles::config());
        $idfm = $profiles->find('idfm');
        $tcl = $profiles->find('tcl');
        self::assertNotNull($idfm);
        self::assertNotNull($tcl);

        self::assertSame('STIF:StopPoint:Q:12345:', $idfm->stopRef('12345'));
        self::assertSame('STIF:StopPoint:Q:12345:', $idfm->stopRef('STIF:StopPoint:Q:12345:'));
        self::assertSame('C3', $tcl->lineRef('TCL:Line::C3:'));
        self::assertNull($idfm->lineRef('C3'));
    }

    #[Test]
    public function itNormalizesRefsToTheLastNonEmptyColonSegment(): void
    {
        self::assertSame('C3', SiriRef::code('STIF:Line::C3:'));
        self::assertSame('C3', SiriRef::code('  C3  '));
        self::assertSame('', SiriRef::code(':::'));
        self::assertSame('c3', SiriRef::normalized('STIF:Line::C3:'));
    }

    #[Test]
    public function itMatchesAnAvailableProfileByAliasAndCity(): void
    {
        $profiles = new SiriProfileRegistry(TestSiriProfiles::config());

        self::assertSame('idfm', $profiles->match('RATP', 'Feed', 'Paris')?->key);
        self::assertSame('cts', $profiles->match('CTS', 'CTS network', '')?->key);
        self::assertSame('tcl', $profiles->match('', 'SYTRAL feed', 'Lyon')?->key);
        self::assertSame('idfm', $profiles->match('Île-de-France Mobilités', 'Réseaux urbains', '')?->key);
        self::assertNull($profiles->match('SNCF', 'TER', 'Ile-de-France'));
        self::assertNull($profiles->match('Some Bus', 'Some Bus', 'Lyon'));
        self::assertNull($profiles->match('LeMet', 'LeMet', 'Metz'));
    }

    #[Test]
    public function itMatchesAnIdfmRowOutsideItsListedCities(): void
    {
        $profiles = new SiriProfileRegistry(TestSiriProfiles::config());

        self::assertSame('idfm', $profiles->match('IDFM', 'Feed', 'Saint-Denis')?->key);
    }

    #[Test]
    public function itDoesNotMatchAConflictingCity(): void
    {
        $profiles = new SiriProfileRegistry(TestSiriProfiles::config());

        self::assertNull($profiles->match('TCL', 'TCL network', 'Limoges'));
        self::assertNull($profiles->match('TCL', 'TCL network', ''));
        self::assertNull($profiles->match('SYTRAL', 'SYTRAL network', 'Limoges'));
    }

    #[Test]
    public function itDoesNotMatchAnUnavailableProfile(): void
    {
        $profiles = new SiriProfileRegistry(TestSiriProfiles::withUnavailableProfile());

        self::assertSame('idfm', $profiles->match('RATP', 'Feed', 'Paris')?->key);

        $withoutIdfm = new SiriProfileRegistry($this->blankAuth(TestSiriProfiles::config(), 'idfm', 'value'));
        self::assertNull($withoutIdfm->match('RATP', 'Feed', 'Paris'));
    }

    #[Test]
    public function itRejectsAnUnknownService(): void
    {
        $config = TestSiriProfiles::config();
        $config['broken'] = [
            'service' => 'telepathy',
            'endpoint' => 'https://1.1.1.1/broken',
        ];

        $this->expectException(\InvalidArgumentException::class);
        new SiriProfileRegistry($config);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidConfigs(): array
    {
        return [
            'non-array profile' => [['broken' => 'not a mapping']],
            'non-array auth' => [[
                'broken' => [
                    'service' => 'stop_monitoring',
                    'endpoint' => 'https://1.1.1.1/broken',
                    'auth' => 'basic',
                ],
            ]],
            'auth without kind' => [[
                'broken' => [
                    'service' => 'stop_monitoring',
                    'endpoint' => 'https://1.1.1.1/broken',
                    'auth' => ['name' => 'apiKey', 'value' => 'value'],
                ],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('invalidConfigs')]
    public function itRejectsInvalidConfigEntries(array $config): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SiriProfileRegistry($config);
    }

    /**
     * @param array<string, array<string, mixed>> $config
     *
     * @return array<string, array<string, mixed>>
     */
    private function blankAuth(array $config, string $profile, string $field): array
    {
        $auth = $config[$profile]['auth'] ?? [];
        if (is_array($auth)) {
            $auth[$field] = '';
            $config[$profile]['auth'] = $auth;
        }

        return $config;
    }
}
