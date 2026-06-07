<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DatabaseSafetyTest
 *
 * Verifies that the test environment is correctly configured to use
 * SQLite in-memory and NEVER connects to PostgreSQL "polyintel".
 *
 * This test must always pass before running any other tests.
 */
class DatabaseSafetyTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_uses_sqlite_connection(): void
    {
        $this->assertEquals(
            'sqlite',
            config('database.default'),
            'Tests must use SQLite. PostgreSQL "polyintel" must never be used in tests.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_uses_in_memory_database(): void
    {
        $this->assertEquals(
            ':memory:',
            config('database.connections.sqlite.database'),
            'Tests must use :memory: SQLite. A file-based or PostgreSQL DB must never be used.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_is_in_testing_environment(): void
    {
        $this->assertEquals(
            'testing',
            app()->environment(),
            'App environment must be "testing" when running tests.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_have_pgsql_as_default(): void
    {
        $this->assertNotEquals(
            'pgsql',
            config('database.default'),
            'PostgreSQL must never be the default connection during tests.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_reference_polyintel_database(): void
    {
        $sqliteDb = config('database.connections.sqlite.database');

        $this->assertNotEquals(
            'polyintel',
            $sqliteDb,
            '"polyintel" database must never be referenced during tests.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function refresh_database_only_affects_sqlite(): void
    {
        // If we reach here without exception, RefreshDatabase is safe.
        // The TestCase guard would have thrown if PostgreSQL was in use.
        $this->assertTrue(true, 'RefreshDatabase is safe — using SQLite :memory:');
    }
}
