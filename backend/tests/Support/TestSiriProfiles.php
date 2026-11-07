<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\SiriProfileRegistry;

final class TestSiriProfiles
{
    /**
     * The shipped profiles with test credentials and literal endpoints, so OutboundUrlGuard needs
     * no DNS and MockHttpClient can answer.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function config(): array
    {
        return [
            'idfm' => [
                'label' => 'Île-de-France Mobilités',
                'service' => 'stop_monitoring',
                'endpoint' => 'https://1.1.1.1/idfm',
                'auth' => ['kind' => 'header', 'name' => 'apiKey', 'value' => 'test-prim-key'],
                'stop_ref' => 'STIF:StopPoint:Q:{code}:',
                'aliases' => ['IDFM', 'RATP', 'Transilien', 'Île-de-France'],
                'cities' => ['Paris', 'Île-de-France'],
                'city_optional' => true,
            ],
            'cts' => [
                'label' => 'CTS Strasbourg',
                'service' => 'estimated_timetable',
                'endpoint' => 'https://1.1.1.1/cts',
                'auth' => ['kind' => 'basic', 'user' => 'test-cts-token', 'password' => ''],
                'query' => ['GetStopIdInsteadOfStopCode' => 'true'],
                'line_ref' => '{code}',
                'alerts_endpoint' => 'https://1.1.1.1/cts-alerts',
                'aliases' => ['CTS'],
                'cities' => ['Strasbourg'],
            ],
            'tcl' => [
                'label' => 'TCL Lyon',
                'service' => 'estimated_timetable',
                'endpoint' => 'https://1.1.1.1/tcl',
                'auth' => [
                    'kind' => 'basic',
                    'user' => 'test-sytral-user',
                    'password' => 'test-sytral-pass',
                    'password_required' => true,
                ],
                'line_ref' => '{code}',
                'alerts_endpoint' => 'https://1.1.1.1/tcl-alerts',
                'aliases' => ['SYTRAL'],
                'cities' => ['Lyon'],
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>>|null $config
     */
    public static function registry(?array $config = null): SiriProfileRegistry
    {
        return new SiriProfileRegistry($config ?? self::config());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function withUnavailableProfile(): array
    {
        $config = self::config();
        $config['offline'] = [
            'label' => 'Offline operator',
            'service' => 'estimated_timetable',
            'endpoint' => 'https://1.1.1.1/offline',
            'auth' => ['kind' => 'header', 'name' => 'apiKey', 'value' => ''],
            'aliases' => ['Offline'],
            'cities' => ['Nowhere'],
        ];

        return $config;
    }
}
