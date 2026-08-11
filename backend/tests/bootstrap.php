<?php

date_default_timezone_set('UTC');

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Force test environment before dotenv is loaded.
$_SERVER['APP_ENV'] = 'test';
$_ENV['APP_ENV'] = 'test';
$_SERVER['APP_API_KEY'] = 'test-key';
$_ENV['APP_API_KEY'] = 'test-key';
$_SERVER['OVERPASS_MIRRORS_RANDOMIZE'] = 'true';
$_ENV['OVERPASS_MIRRORS_RANDOMIZE'] = 'true';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
