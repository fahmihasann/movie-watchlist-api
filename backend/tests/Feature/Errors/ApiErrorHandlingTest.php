<?php

namespace Tests\Feature\Errors;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\AssertsApiError;
use Tests\TestCase;

class ApiErrorHandlingTest extends TestCase
{
    use AssertsApiError;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/test-errors/forbidden', fn () => abort(403));
        Route::get('/api/test-errors/authorization', fn () => throw new AuthorizationException('secret'));
        Route::get('/api/test-errors/too-large', fn () => throw new PostTooLargeException);
        Route::get('/api/test-errors/throttled', fn () => throw new ThrottleRequestsException(
            'secret',
            null,
            ['Retry-After' => '60'],
        ));
        Route::get('/api/test-errors/boom', fn () => throw new RuntimeException('TOP_SECRET_MARKER'));
    }

    private function postRaw(string $uri, string $body, ?string $accept = null): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($accept !== null) {
            $server['HTTP_ACCEPT'] = $accept;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }

    private function tokenFor(User $user): string
    {
        $token = auth('api')->login($user);

        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return $token;
    }

    public function test_unknown_api_routes_are_json_404_with_or_without_accept(): void
    {
        foreach ([null, 'text/html'] as $accept) {
            $response = $accept === null
                ? $this->get('/api/does-not-exist')
                : $this->get('/api/does-not-exist', ['Accept' => $accept]);

            $this->assertApiError($response, 404, 'Not found.');
        }
    }

    public function test_wrong_method_is_json_405_and_keeps_the_allow_header(): void
    {
        foreach ([null, 'text/html'] as $accept) {
            $response = $accept === null
                ? $this->delete('/api/auth/login')
                : $this->delete('/api/auth/login', [], ['Accept' => $accept]);

            $this->assertApiError($response, 405, 'Method not allowed.');
            $response->assertHeader('Allow');
        }
    }

    public function test_malformed_json_is_400_with_or_without_accept(): void
    {
        foreach ([null, 'text/html'] as $accept) {
            $response = $this->postRaw('/api/auth/login', '{"email":', $accept);

            $this->assertApiError($response, 400, 'Malformed JSON body.');
        }
    }

    public function test_valid_json_that_is_not_an_object_is_400_with_or_without_accept(): void
    {
        foreach (['[]', '"hello"', 'null', '42'] as $body) {
            foreach ([null, 'text/html'] as $accept) {
                $response = $this->postRaw('/api/auth/login', $body, $accept);

                $this->assertApiError($response, 400, 'JSON body must be an object.');
            }
        }
    }

    public function test_private_routes_return_json_401_with_or_without_accept(): void
    {
        foreach (['/api/watchlist', '/api/watched'] as $uri) {
            $this->assertApiError($this->get($uri), 401, 'Unauthenticated.');
            $this->assertApiError(
                $this->get($uri, ['Accept' => 'text/html']),
                401,
                'Unauthenticated.',
            );
        }
    }

    public function test_broken_tokens_return_json_401_with_or_without_accept(): void
    {
        foreach ([null, 'text/html'] as $accept) {
            $headers = ['Authorization' => 'Bearer not-a-valid-token'];

            if ($accept !== null) {
                $headers['Accept'] = $accept;
            }

            $this->assertApiError(
                $this->get('/api/watchlist', $headers),
                401,
                'Unauthenticated.',
            );
        }
    }

    public function test_forbidden_routes_return_json_403_with_or_without_accept(): void
    {
        foreach (['/api/test-errors/forbidden', '/api/test-errors/authorization'] as $uri) {
            foreach ([null, 'text/html'] as $accept) {
                $response = $accept === null
                    ? $this->get($uri)
                    : $this->get($uri, ['Accept' => $accept]);

                $this->assertApiError($response, 403, 'Forbidden.');
            }
        }
    }

    public function test_post_too_large_returns_json_413_with_or_without_accept(): void
    {
        foreach ([null, 'text/html'] as $accept) {
            $response = $accept === null
                ? $this->get('/api/test-errors/too-large')
                : $this->get('/api/test-errors/too-large', ['Accept' => $accept]);

            $this->assertApiError($response, 413, 'Payload too large.');
        }
    }

    public function test_throttled_requests_return_json_429_and_keep_retry_after(): void
    {
        foreach ([null, 'text/html'] as $accept) {
            $response = $accept === null
                ? $this->get('/api/test-errors/throttled')
                : $this->get('/api/test-errors/throttled', ['Accept' => $accept]);

            $this->assertApiError(
                $response,
                429,
                'Too many requests. Please try again later.',
            );
            $response->assertHeader('Retry-After', '60');
        }
    }

    public function test_unexpected_errors_are_generic_json_500_without_leaks(): void
    {
        foreach ([true, false] as $debug) {
            config(['app.debug' => $debug]);

            foreach ([null, 'text/html', 'application/json'] as $accept) {
                $response = $accept === null
                    ? $this->get('/api/test-errors/boom')
                    : $this->get('/api/test-errors/boom', ['Accept' => $accept]);

                $this->assertApiError($response, 500, 'Server error.');

                $body = (string) $response->getContent();

                foreach (['TOP_SECRET_MARKER', 'trace', '"file"', '"line"', 'SQLSTATE', base_path()] as $needle) {
                    $this->assertStringNotContainsString($needle, $body);
                }
            }
        }
    }

    public function test_numeric_ids_that_overflow_php_int_are_404_not_500(): void
    {
        foreach (['99999999999999999999', '9223372036854775807'] as $id) {
            $this->assertApiError($this->get('/api/movies/'.$id), 404, 'Not found.');
        }

        $user = User::factory()->create([
            'username' => 'huge_id_user',
            'email' => 'huge_id@example.com',
            'password' => 'password123',
        ]);
        $token = $this->tokenFor($user);

        $this->assertApiError(
            $this->delete('/api/watchlist/99999999999999999999', [
                'Authorization' => 'Bearer '.$token,
            ]),
            404,
            'Not found.',
        );
    }
}
