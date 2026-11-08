<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWatchlistRequest;
use App\Http\Resources\MovieResource;
use App\Services\WatchlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WatchlistController extends Controller
{
    public function __construct(private readonly WatchlistService $watchlistService) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            MovieResource::collection($this->watchlistService->list($request->user()))
        );
    }

    public function store(StoreWatchlistRequest $request): JsonResponse
    {
        $movie = $this->watchlistService->add($request->user(), $request->integer('movieId'));

        return response()->json([
            'message' => 'Added to watchlist.',
            'data' => new MovieResource($movie),
        ], 201);
    }

    public function destroy(Request $request, int $movieId): JsonResponse
    {
        $this->watchlistService->remove($request->user(), $movieId);

        return response()->json([
            'message' => 'Removed from watchlist.',
        ]);
    }
}
