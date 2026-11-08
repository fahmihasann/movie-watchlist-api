<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MovieController;
use App\Http\Controllers\Api\MovieRecommendationController;
use App\Http\Controllers\Api\WatchedController;
use App\Http\Controllers\Api\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok']));

Route::prefix('auth')->middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::get('/movies', [MovieController::class, 'index']);
Route::post('/movies/recommend', MovieRecommendationController::class);
Route::get('/movies/{id}', [MovieController::class, 'show'])->where('id', '[0-9]{1,18}');

Route::middleware('auth:api')->group(function () {
    Route::get('/watchlist', [WatchlistController::class, 'index']);
    Route::post('/watchlist', [WatchlistController::class, 'store']);
    Route::delete('/watchlist/{movieId}', [WatchlistController::class, 'destroy'])->where('movieId', '[0-9]{1,18}');

    Route::get('/watched', [WatchedController::class, 'index']);
    Route::post('/watched', [WatchedController::class, 'store']);
});
