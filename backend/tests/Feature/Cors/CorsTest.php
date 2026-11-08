<?php

namespace Tests\Feature\Cors;

use Database\Seeders\MovieSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\AssertsApiError;
use Tests\TestCase;

class CorsTest extends TestCase
{
    use AssertsApiError;
    use RefreshDatabase;

    private const ORIGIN = 'http://localhost:5500';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MovieSeeder::class);

        Route::get('/api/cors-test/boom', fn () => throw new RuntimeException('CORS_TEST_BOOM'));
        Route::get('/api/cors-test/forbidden', fn () => abort(403));
        Route::get('/api/cors-test/too-large', fn () => throw new PostTooLargeException);
        Route::get('/api/cors-test/throttled', fn () => response()->json(['status' => 'ok']))
            ->middleware('throttle:1,1');
    }

    private function preflight(string $uri, string $method, ?string $origin = null): TestResponse
    {
        return $this->call('OPTIONS', $uri, [], [], [], [
            'HTTP_ORIGIN' => $origin ?? self::ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);
    }

    private function postJsonWithoutAccept(string $uri, string $body): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => self::ORIGIN,
        ], $body);
    }

    private function assertSingleCorsHeader(TestResponse $response, string $expected = '*'): void
    {
        $values = $response->headers->all('access-control-allow-origin');

        $this->assertCount(1, $values, 'Expected exactly one Access-Control-Allow-Origin header.');
        $this->assertSame($expected, $values[0]);
    }

    private function assertCorsError(TestResponse $response, int $status, string $message): void
    {
        $this->assertApiError($response, $status, $message);
        $this->assertSingleCorsHeader($response);
    }

    public function test_simple_request_includes_cors_header(): void
    {
        $response = $this->get('/api/movies', ['Origin' => self::ORIGIN]);

        $response->assertOk();
        $this->assertSingleCorsHeader($response);
    }

    public function test_null_origin_is_allowed_with_default_wildcard(): void
    {
        $response = $this->get('/api/movies', ['Origin' => 'null']);

        $response->assertOk();
        $this->assertSingleCorsHeader($response);
    }

    public function test_pagination_headers_are_exposed_to_browsers(): void
    {
        $response = $this->get('/api/movies?page=1&per_page=10', ['Origin' => self::ORIGIN]);

        $response->assertOk()
            ->assertHeader('X-Total-Count', '20')
            ->assertHeader('X-Total-Pages', '2')
            ->assertHeader('X-Page', '1')
            ->assertHeader('X-Per-Page', '10')
            ->assertHeader(
                'Access-Control-Expose-Headers',
                'X-Total-Count, X-Total-Pages, X-Page, X-Per-Page, Retry-After',
            );

        $this->assertSingleCorsHeader($response);
    }

    public function test_preflight_is_allowed_for_every_documented_route(): void
    {
        $routes = [
            '/api/movies' => 'GET',
            '/api/movies/recommend' => 'POST',
            '/api/watchlist' => 'POST',
            '/api/watchlist/1' => 'DELETE',
            '/api/watched' => 'POST',
            '/api/auth/login' => 'POST',
            '/api/auth/register' => 'POST',
        ];

        foreach ($routes as $uri => $method) {
            $response = $this->preflight($uri, $method);

            $response->assertStatus(204)
                ->assertHeader('Access-Control-Max-Age', '600')
                ->assertHeaderContains('Access-Control-Allow-Methods', $method)
                ->assertHeaderContains('Access-Control-Allow-Headers', 'authorization')
                ->assertHeaderContains('Access-Control-Allow-Headers', 'content-type');

            $this->assertSingleCorsHeader($response);
        }
    }

    public function test_preflight_does_not_grant_a_method_outside_the_contract(): void
    {
        $response = $this->preflight('/api/watchlist', 'PUT');

        $response->assertStatus(204);
        $this->assertStringNotContainsString(
            'PUT',
            (string) $response->headers->get('Access-Control-Allow-Methods'),
        );
    }

    public function test_unauthorized_response_includes_cors_header(): void
    {
        $this->assertCorsError(
            $this->get('/api/watchlist', ['Origin' => self::ORIGIN]),
            401,
            'Unauthenticated.',
        );
    }

    public function test_not_found_response_includes_cors_header(): void
    {
        $this->assertCorsError(
            $this->get('/api/does-not-exist', ['Origin' => self::ORIGIN]),
            404,
            'Not found.',
        );
    }

    public function test_method_not_allowed_response_includes_cors_header(): void
    {
        $response = $this->delete('/api/auth/login', [], ['Origin' => self::ORIGIN]);

        $this->assertCorsError($response, 405, 'Method not allowed.');
        $response->assertHeader('Allow');
    }

    public function test_malformed_json_response_includes_cors_header(): void
    {
        $this->assertCorsError(
            $this->postJsonWithoutAccept('/api/auth/login', '{"email":'),
            400,
            'Malformed JSON body.',
        );
    }

    public function test_duplicate_registration_response_includes_cors_header(): void
    {
        $payload = json_encode([
            'username' => 'cors_user',
            'email' => 'cors@example.com',
            'password' => 'secret-password',
        ]);

        $this->postJsonWithoutAccept('/api/auth/register', $payload)->assertCreated();

        $this->assertCorsError(
            $this->postJsonWithoutAccept('/api/auth/register', $payload),
            409,
            'Email already registered.',
        );
    }

    public function test_forbidden_response_includes_cors_header(): void
    {
        $this->assertCorsError(
            $this->get('/api/cors-test/forbidden', ['Origin' => self::ORIGIN]),
            403,
            'Forbidden.',
        );
    }

    public function test_payload_too_large_response_includes_cors_header(): void
    {
        $this->assertCorsError(
            $this->get('/api/cors-test/too-large', ['Origin' => self::ORIGIN]),
            413,
            'Payload too large.',
        );
    }

    public function test_request_exceeding_post_max_size_includes_cors_header(): void
    {
        $response = $this->call('POST', '/api/auth/login', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '43000000',
            'HTTP_ORIGIN' => self::ORIGIN,
        ], '{}');

        $this->assertCorsError($response, 413, 'Payload too large.');
    }

    public function test_throttled_response_includes_cors_header_and_retry_after(): void
    {
        $this->get('/api/cors-test/throttled', ['Origin' => self::ORIGIN])->assertOk();

        $response = $this->get('/api/cors-test/throttled', ['Origin' => self::ORIGIN]);

        $this->assertCorsError(
            $response,
            429,
            'Too many requests. Please try again later.',
        );

        $response->assertHeader('Retry-After')
            ->assertHeaderContains('Access-Control-Expose-Headers', 'Retry-After');
    }

    public function test_unhandled_error_response_includes_cors_header(): void
    {
        $this->assertCorsError(
            $this->get('/api/cors-test/boom', ['Origin' => self::ORIGIN]),
            500,
            'Server error.',
        );
    }

    public function test_gemini_failure_response_includes_cors_header(): void
    {
        config([
            'services.gemini.key' => 'test-api-key',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Internal Gemini failure.'],
            ], 500),
        ]);

        $this->assertCorsError(
            $this->postJsonWithoutAccept('/api/movies/recommend', '{"prompt":"film sci-fi"}'),
            502,
            'AI recommendation service is unavailable. Please try again later.',
        );
    }

    public function test_gemini_connection_failure_response_includes_cors_header(): void
    {
        config([
            'services.gemini.key' => 'test-api-key',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => fn () => throw new ConnectionException('timed out'),
        ]);

        $this->assertCorsError(
            $this->postJsonWithoutAccept('/api/movies/recommend', '{"prompt":"film sci-fi"}'),
            502,
            'AI recommendation service is unavailable. Please try again later.',
        );
    }

    public function test_null_byte_rejection_includes_cors_header(): void
    {
        $this->assertCorsError(
            $this->get('/api/movies?q=a%00b', ['Origin' => self::ORIGIN]),
            400,
            'The request must not contain null bytes.',
        );
    }

    public function test_restricted_origins_reflect_the_allowed_origin_and_set_vary(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000', 'http://localhost:5500']]);

        $response = $this->get('/api/movies', ['Origin' => 'http://localhost:3000']);

        $response->assertOk();
        $this->assertSingleCorsHeader($response, 'http://localhost:3000');
        $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
    }

    public function test_restricted_origins_do_not_allow_an_unlisted_actual_request(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000', 'http://localhost:5500']]);

        $response = $this->get('/api/movies', ['Origin' => 'http://evil.example']);

        $response->assertOk();
        $this->assertCount(0, $response->headers->all('access-control-allow-origin'));
        $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
    }

    public function test_restricted_origins_do_not_allow_an_unlisted_preflight(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000', 'http://localhost:5500']]);

        $response = $this->preflight('/api/watchlist', 'POST', 'http://evil.example');

        $response->assertStatus(204);
        $this->assertCount(0, $response->headers->all('access-control-allow-origin'));
        $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
    }

    public function test_access_control_allow_origin_is_never_duplicated(): void
    {
        $responses = [
            $this->get('/api/movies', ['Origin' => self::ORIGIN]),
            $this->get('/api/watchlist', ['Origin' => self::ORIGIN]),
            $this->get('/api/does-not-exist', ['Origin' => self::ORIGIN]),
            $this->preflight('/api/watchlist', 'POST'),
            $this->get('/api/cors-test/too-large', ['Origin' => self::ORIGIN]),
        ];

        foreach ($responses as $response) {
            $this->assertCount(1, $response->headers->all('access-control-allow-origin'));
        }
    }
}
