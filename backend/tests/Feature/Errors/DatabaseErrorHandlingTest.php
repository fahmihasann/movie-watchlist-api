<?php

namespace Tests\Feature\Errors;

use App\Models\User;
use Database\Seeders\MovieSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\Concerns\AssertsApiError;
use Tests\TestCase;

class DatabaseErrorHandlingTest extends TestCase
{
    use AssertsApiError;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MovieSeeder::class);

        $user = User::factory()->create([
            'username' => 'db_error_user',
            'email' => 'db_error@example.com',
            'password' => 'password123',
        ]);

        Route::get('/api/test-db/duplicate', function () use ($user) {
            $row = [
                'user_id' => $user->id,
                'movie_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('watchlists')->insert($row);
            DB::table('watchlists')->insert($row);
        });

        Route::get('/api/test-db/fk-child', fn () => DB::table('watchlists')->insert([
            'user_id' => 999999,
            'movie_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        Route::get('/api/test-db/fk-parent', function () {
            $previous = new PDOException('probe');
            $previous->errorInfo = ['23000', 1451, 'probe'];

            throw new QueryException(
                'mysql',
                'delete from probe_parent where id = ?',
                [1],
                $previous,
            );
        });
    }

    private function tokenFor(User $user): string
    {
        $token = auth('api')->login($user);

        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return $token;
    }

    private function assertNoSqlLeak(string $body): void
    {
        foreach (['SQLSTATE', 'insert into', 'delete from', 'select ', 'watchlists', 'probe_parent'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_unique_violation_maps_to_409_without_sql(): void
    {
        $response = $this->get('/api/test-db/duplicate');

        $this->assertApiError($response, 409, 'Resource already exists.');
        $this->assertNoSqlLeak((string) $response->getContent());
    }

    public function test_missing_foreign_key_parent_maps_to_404_without_sql(): void
    {
        $response = $this->get('/api/test-db/fk-child');

        $this->assertApiError($response, 404, 'Related resource not found.');
        $this->assertNoSqlLeak((string) $response->getContent());
    }

    public function test_delete_restricted_by_foreign_key_maps_to_409_without_sql(): void
    {
        $response = $this->get('/api/test-db/fk-parent');

        $this->assertApiError($response, 409, 'Resource is still referenced.');
        $this->assertNoSqlLeak((string) $response->getContent());
    }

    public function test_duplicate_watchlist_request_returns_409_not_500(): void
    {
        $user = User::query()->where('username', 'db_error_user')->firstOrFail();
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/watchlist', ['movieId' => 1])
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/watchlist', ['movieId' => 1])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Movie already in watchlist.']);
    }
}
