<?php

namespace App\Http\Requests;

use App\Rules\MaxBytes;
use App\Rules\NoNullBytes;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'username' => ['required', 'string', 'min:3', 'max:50', new NoNullBytes],
            'email' => ['required', 'string', 'email', 'max:255', new NoNullBytes],
            'password' => ['required', 'string', 'min:8', new MaxBytes(72), 'max:72', new NoNullBytes],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => trim($this->input('email'))]);
        }
    }
}
