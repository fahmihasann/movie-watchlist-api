<?php

namespace Tests\Feature\Errors;

use App\Exceptions\ApiException;
use App\Exceptions\DuplicateUserException;
use App\Models\User;
use Database\Seeders\MovieSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\AssertsApiError;
use Tests\TestCase;

class ErrorLoggingTest extends TestCase
{
    use AssertsApiError;
    use RefreshDatabase;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = storage_path('framework/testing/'.Str::uuid().'.log');
        File::ensureDirectoryExists(dirname($this->logPath));
        File::put($this->logPath, '');

        config([
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->logPath,
        ]);

        app('log')->forgetChannel('single');
        app('log')->forgetChannel('stack');
    }

    protected function tearDown(): void
    {
        File::delete($this->logPath);

        parent::tearDown();
    }

    private function logContents(): string
    {
        return File::exists($this->logPath) ? File::get($this->logPath) : '';
    }

    public function test_normal_4xx_errors_are_not_logged_as_errors(): void
    {
        Route::get('/api/test-log/conflict', fn () => throw DuplicateUserException::email());
        Route::get('/api/test-log/missing', fn () => throw ApiException::movieNotFound());
        Route::get('/api/test-log/forbidden', fn () => abort(403));

        $this->assertApiError($this->postJson('/api/auth/login', []), 400);
        $this->assertApiError($this->get('/api/watchlist'), 401);
        $this->assertApiError($this->get('/api/does-not-exist'), 404);
        $this->assertApiError($this->get('/api/test-log/missing'), 404);
        $this->assertApiError($this->get('/api/test-log/forbidden'), 403);
        $this->assertApiError($this->get('/api/test-log/conflict'), 409);

        $this->assertStringNotContainsString('ERROR', $this->logContents());
        $this->assertStringNotContainsString('Movie not found.', $this->logContents());
    }

    public function test_unexpected_500_logs_context_without_request_secrets(): void
    {
        Route::post('/api/test-log/boom', fn () => throw new RuntimeException('boom'));

        $passwordMarker = 'PASSWORD_MARKER_'.Str::random(12);
        $tokenMarker = 'TOKEN_MARKER_'.Str::random(12);

        $this->assertApiError(
            $this->withHeader('Authorization', 'Bearer '.$tokenMarker)
                ->postJson('/api/test-log/boom', [
                    'password' => $passwordMarker,
                    'token' => $tokenMarker,
                ]),
            500,
            'Server error.',
        );

        $log = $this->logContents();

        $this->assertStringContainsString('Unhandled exception while handling API request.', $log);
        $this->assertStringContainsString('RuntimeException', $log);
        $this->assertStringContainsString('POST', $log);
        $this->assertStringContainsString('api/test-log/boom', $log);

        foreach ([$passwordMarker, $tokenMarker, 'password', 'Authorization', 'Bearer'] as $needle) {
            $this->assertStringNotContainsString($needle, $log);
        }
    }

    public function test_query_exception_logs_driver_code_without_sql_or_bindings(): void
    {
        $emailMarker = 'binding-marker-'.Str::random(8).'@example.test';
        $passwordMarker = 'PASSWORD_MARKER_'.Str::random(12);

        User::factory()->create([
            'username' => 'logging_user',
            'email' => $emailMarker,
            'password' => 'password123',
        ]);

        Route::get('/api/test-log/query', function () use ($emailMarker, $passwordMarker) {
            DB::table('users')->insert([
                'username' => 'logging_user_2',
                'email' => $emailMarker,
                'password' => $passwordMarker,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertApiError($this->get('/api/test-log/query'), 409, 'Resource already exists.');

        $log = $this->logContents();

        $this->assertStringContainsString('Database exception while handling API request.', $log);
        $this->assertStringContainsString('1062', $log);

        foreach ([$emailMarker, $passwordMarker, 'insert into', 'SQLSTATE'] as $needle) {
            $this->assertStringNotContainsString($needle, $log);
        }
    }

    public function test_gemini_failure_log_does_not_contain_the_api_key(): void
    {
        $this->seed(MovieSeeder::class);

        $keyMarker = 'GEMINI_KEY_MARKER_'.Str::random(12);

        config([
            'services.gemini.key' => $keyMarker,
            'services.gemini.model' => 'gemini-test-model',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'upstream failure'],
            ], 500),
        ]);

        $this->assertApiError(
            $this->postJson('/api/movies/recommend', ['prompt' => 'film sci-fi']),
            502,
            'AI recommendation service is unavailable. Please try again later.',
        );

        $log = $this->logContents();

        $this->assertStringNotContainsString($keyMarker, $log);
        $this->assertStringNotContainsString('x-goog-api-key', $log);
    }
}
