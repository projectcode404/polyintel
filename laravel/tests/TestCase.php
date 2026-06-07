<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstProductionDatabase();
    }

    /**
     * CRITICAL SAFETY GUARD
     *
     * Immediately abort if tests are about to run against PostgreSQL.
     * This protects the "polyintel" database from being wiped by RefreshDatabase.
     *
     * If this exception is thrown, check:
     *   - phpunit.xml has DB_CONNECTION=sqlite and DB_DATABASE=:memory:
     *   - .env.testing does NOT contain DB_CONNECTION=pgsql
     *   - No test is manually overriding the database connection
     */
    private function guardAgainstProductionDatabase(): void
    {
        $connection = config('database.default');
        $database   = config('database.connections.' . $connection . '.database');

        if ($connection === 'pgsql' || $connection === 'postgres') {
            throw new \RuntimeException(
                "🚨 DANGER: Tests are attempting to use PostgreSQL!\n" .
                "Connection: {$connection}\n" .
                "Database: {$database}\n\n" .
                "This would wipe the production 'polyintel' database.\n" .
                "Check phpunit.xml and .env.testing — DB_CONNECTION must be 'sqlite'."
            );
        }

        if ($database === 'polyintel') {
            throw new \RuntimeException(
                "🚨 DANGER: Tests are targeting the 'polyintel' database!\n" .
                "This would destroy production data.\n" .
                "Check phpunit.xml — DB_DATABASE must be ':memory:'."
            );
        }
    }
}
