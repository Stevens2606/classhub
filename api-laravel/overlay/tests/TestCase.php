<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety net: refuse to run if the app is not in the "testing" environment on in-memory SQLite.
     * RefreshDatabase wipes the database, so this must never point at the dev PostgreSQL.
     * It runs right after the app boots, before any migration happens.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $env = $this->app->environment();
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $contentUrl = config('classhub.content_service.url');

        if ($env !== 'testing' || $connection !== 'sqlite' || $database !== ':memory:' || $contentUrl !== 'http://content.test') {
            throw new \RuntimeException(sprintf(
                'Unsafe test setup: env=%s, db=%s (%s), content url=%s. Check phpunit.xml.',
                $env, $connection, $database, $contentUrl
            ));
        }
    }
}