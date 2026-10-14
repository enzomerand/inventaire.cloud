<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Auth\EmailAddress;
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
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => EmailAddress::rules(),
        ];
    }

    /**
     * The address in its canonical form ({@see EmailAddress::normalize()}), the only form that is stored and looked up.
     */
    public function email(): string
    {
        return EmailAddress::normalize($this->string('email')->toString());
    }

    /**
     * Normalizes the address before it is validated, so that what is checked is what is used.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => EmailAddress::normalize($this->input('email'))]);
        }
    }
}
