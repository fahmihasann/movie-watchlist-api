<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Run before RefreshDatabase (which boots inside parent::setUpTraits()).
     */
    protected function setUpTraits()
    {
        $this->assertTestingDatabaseConfigured();

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTestingDatabase();
    }

    private function assertTestingDatabaseConfigured(): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (! is_string($database) || ! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                'Refusing to run tests: the default database connection must target a database ending in "_test".'
            );
        }
    }

    private function assertTestingDatabase(): void
    {
        $database = DB::connection()->getDatabaseName();

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against database [{$database}]."
            );
        }
    }
}
