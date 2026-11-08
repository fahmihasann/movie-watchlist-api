<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexMoviesRequest;
use App\Http\Resources\MovieResource;
use App\Services\MovieService;
use Illuminate\Http\JsonResponse;

class MovieController extends Controller
{
    public function __construct(private readonly MovieService $movieService) {}

    public function index(IndexMoviesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $genre = $validated['genre'] ?? null;
        $search = $validated['search'] ?? null;
        $shuffle = $request->boolean('shuffle');
        $seed = isset($validated['seed']) ? (int) $validated['seed'] : null;

        if ($request->query->has('page') || $request->query->has('per_page')) {
            $page = isset($validated['page']) ? (int) $validated['page'] : 1;
            $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 10;

            $paginator = $this->movieService->paginate(
                $genre,
                $search,
                $shuffle,
                $seed,
                $perPage,
                $page,
            );

            return response()
                ->json(MovieResource::collection($paginator->items()))
                ->header('X-Total-Count', (string) $paginator->total())
                ->header('X-Total-Pages', (string) $paginator->lastPage())
                ->header('X-Page', (string) $paginator->currentPage())
                ->header('X-Per-Page', (string) $paginator->perPage());
        }

        $movies = $this->movieService->list($genre, $search, $shuffle, $seed);

        return response()->json(MovieResource::collection($movies));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(new MovieResource($this->movieService->find($id)));
    }
}
