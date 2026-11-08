<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecommendMovieRequest;
use App\Services\GeminiService;
use Illuminate\Http\JsonResponse;

class MovieRecommendationController extends Controller
{
    public function __construct(private readonly GeminiService $geminiService) {}

    public function __invoke(RecommendMovieRequest $request): JsonResponse
    {
        $recommendation = $this->geminiService->recommend(
            (string) $request->validated('prompt'),
        );

        return response()->json([
            'movie_id' => $recommendation['movie_id'],
            'reasoning' => $recommendation['reasoning'],
        ]);
    }
}
