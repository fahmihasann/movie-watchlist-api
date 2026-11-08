<?php

namespace Tests\Feature\Movies;

use App\Models\Movie;
use Database\Seeders\MovieSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MovieRecommendationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MovieSeeder::class);

        config([
            'services.gemini.key' => 'test-api-key',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
        ]);

        Http::preventStrayRequests();
    }

    /**
     * @return array<string, mixed>
     */
    private function geminiResponse(int $movieId, string $reasoning = 'Cocok dengan preferensi Anda.'): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'movie_id' => $movieId,
                                    'reasoning' => $reasoning,
                                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ];
    }

    public function test_it_returns_a_movie_recommendation(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse(1, 'Film ini cocok dengan preferensi sci-fi Anda.'),
            ),
        ]);

        $response = $this->postJson('/api/movies/recommend', [
            'prompt' => 'Saya ingin film sci-fi dengan alur cerita kompleks dan penuh misteri.',
        ]);

        $response->assertOk()
            ->assertExactJson([
                'movie_id' => 1,
                'reasoning' => 'Film ini cocok dengan preferensi sci-fi Anda.',
            ]);

        $this->assertIsInt($response->json('movie_id'));
        $this->assertIsString($response->json('reasoning'));
        Http::assertSentCount(1);
    }

    public function test_prompt_is_required(): void
    {
        Http::fake();

        $this->postJson('/api/movies/recommend', [])
            ->assertStatus(400)
            ->assertJsonStructure(['message', 'errors' => ['prompt']]);

        Http::assertNothingSent();
    }

    public function test_prompt_must_be_a_string(): void
    {
        Http::fake();

        $this->postJson('/api/movies/recommend', [
            'prompt' => ['not', 'a', 'string'],
        ])
            ->assertStatus(400)
            ->assertJsonStructure(['message', 'errors' => ['prompt']]);

        Http::assertNothingSent();
    }

    public function test_whitespace_only_prompt_is_rejected(): void
    {
        Http::fake();

        $this->postJson('/api/movies/recommend', [
            'prompt' => '   ',
        ])
            ->assertStatus(400)
            ->assertJsonStructure(['message', 'errors' => ['prompt']]);

        Http::assertNothingSent();
    }

    public function test_it_uses_structured_output_with_movie_ids_from_the_database(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse(1)),
        ]);

        $this->postJson('/api/movies/recommend', [
            'prompt' => 'film misteri dengan atmosfer mendalam',
        ])->assertOk();

        $expectedIds = Movie::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        Http::assertSent(function (Request $request) use ($expectedIds): bool {
            $payload = $request->data();
            $schema = $payload['generationConfig']['responseJsonSchema'] ?? [];

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent'
                && $request->hasHeader('x-goog-api-key', 'test-api-key')
                && ($payload['generationConfig']['responseMimeType'] ?? null) === 'application/json'
                && ($schema['properties']['movie_id']['type'] ?? null) === 'integer'
                && ($schema['properties']['movie_id']['enum'] ?? null) === $expectedIds
                && isset($schema['properties']['reasoning'])
                && ($schema['properties']['reasoning']['type'] ?? null) === 'string'
                && ($schema['required'] ?? null) === ['movie_id', 'reasoning'];
        });
    }

    public function test_the_movie_id_enum_reflects_the_current_database_ids(): void
    {
        Movie::query()->whereKey(20)->delete();

        Movie::query()->create([
            'id' => 99,
            'title' => 'Test Movie',
            'year' => 2026,
            'director' => 'Test Director',
            'genres' => ['Drama'],
            'rating' => 4.5,
            'duration' => '100 min',
            'synopsis' => 'Test synopsis.',
            'poster_url' => 'https://example.com/poster.jpg',
            'cast_members' => ['Test Actor'],
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse(99, 'Film tes.')),
        ]);

        $this->postJson('/api/movies/recommend', [
            'prompt' => 'film tes',
        ])
            ->assertOk()
            ->assertJsonPath('movie_id', 99);

        $expectedIds = Movie::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        Http::assertSent(function (Request $request) use ($expectedIds): bool {
            $schema = $request->data()['generationConfig']['responseJsonSchema'] ?? [];

            return ($schema['properties']['movie_id']['enum'] ?? null) === $expectedIds
                && in_array(99, $expectedIds, true)
                && ! in_array(20, $expectedIds, true);
        });
    }

    public function test_it_sends_every_database_movie_as_grounding_context(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse(1)),
        ]);

        $this->postJson('/api/movies/recommend', [
            'prompt' => 'film apa saja yang bagus',
        ])->assertOk();

        $titles = Movie::query()->pluck('title')->all();

        Http::assertSent(function (Request $request) use ($titles): bool {
            $instructions = $request->data()['systemInstruction']['parts'][0]['text'] ?? '';

            if (! str_contains($instructions, 'Never invent a movie')) {
                return false;
            }

            foreach ($titles as $title) {
                if (! str_contains($instructions, $title)) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_it_retries_once_and_fails_when_the_movie_id_is_not_in_the_database(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiResponse(999))
                ->push($this->geminiResponse(998)),
        ]);

        $this->postJson('/api/movies/recommend', [
            'prompt' => 'film apa saja',
        ])
            ->assertStatus(502)
            ->assertJsonPath(
                'message',
                'AI recommendation service returned an invalid response. Please try again later.',
            );

        Http::assertSentCount(2);
    }

    public function test_it_recovers_when_the_first_recommendation_is_invalid(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiResponse(999, 'Film di luar database.'))
                ->push($this->geminiResponse(1, 'Film yang valid.')),
        ]);

        $this->postJson('/api/movies/recommend', [
            'prompt' => 'film sci-fi',
        ])
            ->assertOk()
            ->assertJsonPath('movie_id', 1)
            ->assertJsonPath('reasoning', 'Film yang valid.');

        Http::assertSentCount(2);
    }

    public function test_it_retries_when_gemini_returns_malformed_json(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    ['text' => 'this is not json'],
                                ],
                            ],
                        ],
                    ],
                ])
                ->push($this->geminiResponse(2, 'Film yang valid.')),
        ]);

        $this->postJson('/api/movies/recommend', [
            'prompt' => 'film misteri',
        ])
            ->assertOk()
            ->assertJsonPath('movie_id', 2);

        Http::assertSentCount(2);
    }

    public function test_it_returns_502_when_gemini_fails(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Internal Gemini failure.'],
            ], 500),
        ]);

        $this->postJson('/api/movies/recommend', [
            'prompt' => 'film sci-fi',
        ])
            ->assertStatus(502)
            ->assertJsonPath(
                'message',
                'AI recommendation service is unavailable. Please try again later.',
            );

        Http::assertSentCount(1);
    }

    public function test_it_returns_500_when_the_api_key_is_not_configured(): void
    {
        config(['services.gemini.key' => null]);

        Http::fake();

        $this->postJson('/api/movies/recommend', [
            'prompt' => 'film sci-fi',
        ])
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'AI recommendation service is not configured.',
            );

        Http::assertNothingSent();
    }
}
