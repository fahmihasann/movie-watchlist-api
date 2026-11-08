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

    /**
     * Encode an empty PHP array as an empty JSON object, not an empty JSON array.
     *
     * The framework helper would otherwise send [] for calls such as
     * postJson('/api/login', []), which is not an object payload. Tests that
     * intentionally exercise an array body use call() with a raw string.
     */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        if ($data !== []) {
            return parent::json($method, $uri, $data, $headers, $options);
        }

        $content = '{}';
        $headers = array_merge([
            'CONTENT_LENGTH' => mb_strlen($content, '8bit'),
            'CONTENT_TYPE' => 'application/json',
            'Accept' => 'application/json',
        ], $headers);

        return $this->call(
            $method,
            $uri,
            [],
            $this->prepareCookiesForJsonRequest(),
            [],
            $this->transformHeadersToServerVars($headers),
            $content,
        );
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
