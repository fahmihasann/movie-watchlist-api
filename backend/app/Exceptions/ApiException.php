<?php

namespace App\Exceptions;

use Exception;

class ApiException extends Exception
{
    private function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function movieNotFound(): self
    {
        return new self(404, 'Movie not found.');
    }

    public static function movieAlreadyInWatchlist(): self
    {
        return new self(409, 'Movie already in watchlist.');
    }

    public static function movieNotInWatchlist(): self
    {
        return new self(404, 'Movie not in watchlist.');
    }

    public static function movieAlreadyReviewed(): self
    {
        return new self(409, 'Movie already reviewed.');
    }

    public static function geminiNotConfigured(): self
    {
        return new self(500, 'AI recommendation service is not configured.');
    }

    public static function geminiUnavailable(): self
    {
        return new self(502, 'AI recommendation service is unavailable. Please try again later.');
    }

    public static function geminiInvalidResponse(): self
    {
        return new self(502, 'AI recommendation service returned an invalid response. Please try again later.');
    }
}
