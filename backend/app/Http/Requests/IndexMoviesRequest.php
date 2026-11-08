<?php

namespace App\Http\Requests;

use App\Rules\NoNullBytes;
use App\Rules\StrictInteger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMoviesRequest extends FormRequest
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
            'genre' => ['sometimes', 'nullable', 'string', 'max:50', new NoNullBytes],
            'search' => ['sometimes', 'nullable', 'string', 'max:100', new NoNullBytes],
            'shuffle' => ['sometimes', 'string', Rule::in(['true', 'false', '1', '0'])],
            'seed' => ['sometimes', 'integer', new StrictInteger, 'min:0', 'max:2147483647'],
            'page' => ['sometimes', 'integer', new StrictInteger, 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', new StrictInteger, 'min:1', 'max:50'],
        ];
    }
}
