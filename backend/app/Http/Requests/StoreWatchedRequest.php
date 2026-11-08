<?php

namespace App\Http\Requests;

use App\Rules\NoNullBytes;
use App\Rules\StrictInteger;
use Illuminate\Foundation\Http\FormRequest;

class StoreWatchedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'movieId' => ['required', 'integer', new StrictInteger, 'min:1', 'max:9223372036854775807'],
            'rating' => ['required', 'integer', new StrictInteger, 'between:1,5'],
            'review' => ['required', 'string', 'max:2000', new NoNullBytes],
        ];
    }
}
