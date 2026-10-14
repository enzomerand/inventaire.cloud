<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The address a sign-in code is asked for.
 */
final class RequestLoginCodeRequest extends FormRequest
{
    /**
     * Anybody may ask for a code: that is how an account is reached or created.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * The address in its canonical form (lowercase, trimmed), the only form that is stored and looked up.
     */
    public function email(): string
    {
        return User::normalizeEmail($this->string('email')->toString());
    }

    /**
     * Normalizes the address before it is validated, so that what is checked is what is used.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => User::normalizeEmail($this->input('email'))]);
        }
    }
}
