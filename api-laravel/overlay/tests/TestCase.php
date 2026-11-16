<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety net: refuse to run if the tests would use anything other than in-memory SQLite.
     * RefreshDatabase wipes the database, so this must never point at the dev PostgreSQL.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $connection = $_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? getenv('DB_CONNECTION');
        $database = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE');

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException('Tests must run on in-memory SQLite. Check phpunit.xml.');
        }
    }
}
