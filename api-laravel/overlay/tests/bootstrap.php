<?php

/*
 * PHPUnit bootstrap. Forces the test settings into every place Laravel may read them from
 * (putenv, $_ENV and $_SERVER) BEFORE the app boots, so the container's own environment
 * (docker compose sets APP_ENV=local and DB_CONNECTION=pgsql) can never win.
 * RefreshDatabase wipes the database, so the tests must never touch the dev PostgreSQL.
 */

require __DIR__.'/../vendor/autoload.php';

$testEnv = [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'CONTENT_SERVICE_URL' => 'http://content.test',
    'CONTENT_SERVICE_KEY' => 'test-key',
    'MIDTRANS_SERVER_KEY' => '',
];

foreach ($testEnv as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}