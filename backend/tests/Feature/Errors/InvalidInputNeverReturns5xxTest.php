<?php

namespace Tests\Feature\Errors;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsApiError;
use Tests\TestCase;

class InvalidInputNeverReturns5xxTest extends TestCase
{
    use AssertsApiError;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create([
            'username' => 'invalid_input_user',
            'email' => 'invalid_input@example.com',
            'password' => 'password123',
        ]);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'invalid_input@example.com',
            'password' => 'password123',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: int}>
     */
    public static function invalidInputProvider(): array
    {
        $rows = [];

        $add = static function (string $label, string $endpoint, array $data, int $status) use (&$rows): void {
            $rows[$label] = [$endpoint, $data, $status];
        };

        $badValues = [
            'null' => null,
            'empty array' => [],
            'empty object' => (object) [],
            'nested array' => [['a' => 1]],
            'boolean' => true,
            'float' => 1.5,
            'negative' => -1,
            'zero' => 0,
            'huge integer' => PHP_INT_MAX,
            'empty string' => '',
            'emoji with null' => "😀\0",
        ];

        $registerBase = [
            'username' => 'valid_name',
            'email' => 'valid@example.com',
            'password' => 'password123',
        ];

        foreach (['username' => 50, 'email' => 255, 'password' => 72] as $field => $max) {
            foreach ($badValues as $case => $value) {
                $data = $registerBase;
                $data[$field] = $value;
                $add("register {$field} {$case}", 'register', $data, 400);
            }

            $data = $registerBase;
            $data[$field] = str_repeat('a', $max + 1);
            $add("register {$field} long string", 'register', $data, 400);
        }

        $loginBase = ['email' => 'valid@example.com', 'password' => 'password123'];

        foreach ($badValues as $case => $value) {
            $data = $loginBase;
            $data['email'] = $value;
            $add("login email {$case}", 'login', $data, 400);
        }

        $data = $loginBase;
        $data['email'] = str_repeat('a', 256);
        $add('login email long string', 'login', $data, 400);

        foreach ($badValues as $case => $value) {
            $data = $loginBase;
            $data['password'] = $value;
            $add("login password {$case}", 'login', $data, 400);
        }

        $data = $loginBase;
        $data['password'] = str_repeat('a', 10000);
        $add('login password long string', 'login', $data, 401);

        $add('movies genre empty string', 'movies_index', ['genre' => ''], 200);
        $add('movies search empty string', 'movies_index', ['search' => ''], 200);

        foreach (['array' => ['x'], 'nested' => [['a' => 1]], 'long' => str_repeat('a', 51), 'null byte' => "a\0"] as $case => $value) {
            $add("movies genre {$case}", 'movies_index', ['genre' => $value], 400);
        }

        foreach (['array' => ['x'], 'nested' => [['a' => 1]], 'long' => str_repeat('a', 101), 'null byte' => "a\0"] as $case => $value) {
            $add("movies search {$case}", 'movies_index', ['search' => $value], 400);
        }

        foreach (['yes', '', 'maybe', ['x'], "a\0"] as $index => $value) {
            $add("movies shuffle invalid {$index}", 'movies_index', ['shuffle' => $value], 400);
        }

        foreach ([-1, 2147483648, '9223372036854775807', '18446744073709551616', 'abc', '', ['x'], 1.5] as $index => $value) {
            $add("movies seed invalid {$index}", 'movies_index', ['seed' => $value], 400);
        }

        $add('movies seed zero is valid', 'movies_index', ['seed' => 0], 200);

        foreach ([0, -1, 10001, '9223372036854775807', 'abc', '', ['x'], 1.5] as $index => $value) {
            $add("movies page invalid {$index}", 'movies_index', ['page' => $value], 400);
        }

        $add('movies page three is valid', 'movies_index', ['page' => 3], 200);

        foreach ([0, -1, 51, 10000, 'abc', '', ['x'], 1.5] as $index => $value) {
            $add("movies per page invalid {$index}", 'movies_index', ['per_page' => $value], 400);
        }

        foreach ($badValues as $case => $value) {
            if ($case === 'huge integer') {
                continue;
            }

            $add("watchlist movieId {$case}", 'watchlist_store', ['movieId' => $value], 400);
        }

        $add('watchlist movieId long digits', 'watchlist_store', ['movieId' => str_repeat('9', 10000)], 400);
        $add('watchlist movieId above bigint unsigned', 'watchlist_store', ['movieId' => '18446744073709551616'], 400);
        $add('watchlist movieId php int max', 'watchlist_store', ['movieId' => PHP_INT_MAX], 404);
        $add('watchlist movieId php int max string', 'watchlist_store', ['movieId' => '9223372036854775807'], 404);

        $watchedBase = ['movieId' => 1, 'rating' => 5, 'review' => 'ok'];

        foreach ($badValues as $case => $value) {
            if ($case === 'huge integer') {
                continue;
            }

            $data = $watchedBase;
            $data['movieId'] = $value;
            $add("watched movieId {$case}", 'watched_store', $data, 400);
        }

        $data = $watchedBase;
        $data['movieId'] = '18446744073709551616';
        $add('watched movieId above bigint unsigned', 'watched_store', $data, 400);

        $data = $watchedBase;
        $data['movieId'] = PHP_INT_MAX;
        $add('watched movieId php int max', 'watched_store', $data, 404);

        $data = $watchedBase;
        $data['movieId'] = '9223372036854775807';
        $add('watched movieId php int max string', 'watched_store', $data, 404);

        foreach ($badValues as $case => $value) {
            $data = $watchedBase;
            $data['rating'] = $value;
            $add("watched rating {$case}", 'watched_store', $data, 400);
        }

        foreach ([6, '6', 'abc', str_repeat('9', 10000)] as $index => $value) {
            $data = $watchedBase;
            $data['rating'] = $value;
            $add("watched rating invalid {$index}", 'watched_store', $data, 400);
        }

        foreach ($badValues as $case => $value) {
            $data = $watchedBase;
            $data['review'] = $value;
            $add("watched review {$case}", 'watched_store', $data, 400);
        }

        $data = $watchedBase;
        $data['review'] = str_repeat('a', 2001);
        $add('watched review long string', 'watched_store', $data, 400);

        foreach ($badValues as $case => $value) {
            $add("recommend prompt {$case}", 'recommend', ['prompt' => $value], 400);
        }

        $add('recommend prompt long string', 'recommend', ['prompt' => str_repeat('a', 1001)], 400);
        $add('recommend prompt whitespace', 'recommend', ['prompt' => '   '], 400);

        return $rows;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidRouteIdProvider(): array
    {
        return [
            '20 digits' => ['99999999999999999999'],
            '19 digits' => ['9223372036854775807'],
            '18 digits' => ['999999999999999999'],
            'non numeric' => ['abc'],
            'negative' => ['-1'],
            'float' => ['1.5'],
            'long digits' => [str_repeat('9', 10000)],
            'null byte' => ['1%00'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidInputProvider')]
    public function test_invalid_input_never_returns_5xx(
        string $endpoint,
        array $data,
        int $expectedStatus,
    ): void {
        if ($endpoint === 'recommend') {
            Http::fake();
        }

        $response = match ($endpoint) {
            'register' => $this->postJson('/api/auth/register', $data),
            'login' => $this->postJson('/api/auth/login', $data),
            'movies_index' => $this->get('/api/movies?'.http_build_query($data)),
            'watchlist_store' => $this->withHeader('Authorization', 'Bearer '.$this->token)
                ->postJson('/api/watchlist', $data),
            'watched_store' => $this->withHeader('Authorization', 'Bearer '.$this->token)
                ->postJson('/api/watched', $data),
            'recommend' => $this->postJson('/api/movies/recommend', $data),
            default => $this->fail("Unknown endpoint [{$endpoint}]."),
        };

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            "Input for [{$endpoint}] produced a 5xx response.",
        );

        if ($expectedStatus >= 400) {
            $this->assertApiError($response, $expectedStatus);
        } else {
            $response->assertStatus($expectedStatus)
                ->assertHeader('content-type', 'application/json');
        }

        if ($endpoint === 'recommend') {
            Http::assertNothingSent();
        }
    }

    #[DataProvider('invalidRouteIdProvider')]
    public function test_invalid_watchlist_route_ids_are_4xx(string $id): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->delete('/api/watchlist/'.$id);

        $this->assertLessThan(500, $response->getStatusCode());
        $this->assertApiError($response, 404);
    }
}
