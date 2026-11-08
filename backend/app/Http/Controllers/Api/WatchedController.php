<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWatchedRequest;
use App\Http\Resources\WatchedResource;
use App\Services\WatchedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WatchedController extends Controller
{
    public function __construct(private readonly WatchedService $watchedService) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            WatchedResource::collection($this->watchedService->list($request->user()))
        );
    }

    public function store(StoreWatchedRequest $request): JsonResponse
    {
        $review = $this->watchedService->add($request->user(), $request->validated());

        return response()->json([
            'message' => 'Added to watched list.',
            'data' => new WatchedResource($review),
        ], 201);
    }
}
