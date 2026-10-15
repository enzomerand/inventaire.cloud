<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Auth\EmailAddress;
use App\Http\Requests\Account\UpdateProfileRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The address and the name of an account an administrator creates.
 */
final class CreateUserRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => EmailAddress::rules(),
            'name' => ['required', 'string', 'max:'.UpdateProfileRequest::NAME_MAX_LENGTH],
        ];
    }

    /**
     * The address in its canonical form ({@see EmailAddress::normalize()}), normalized once, before the validation.
     */
    public function email(): string
    {
        return $this->string('email')->toString();
    }

    /**
     * The name, trimmed.
     */
    public function name(): string
    {
        return $this->string('name')->toString();
    }

    /**
     * Normalizes the address and trims the name before they are validated, so that what is checked is what is used
     * and a name made of spaces counts as missing.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => EmailAddress::normalize($this->input('email'))]);
        }

        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => preg_replace('/^[\s\p{Z}\p{C}]+|[\s\p{Z}\p{C}]+$/u', '', $name) ?? $name]);
        }
    }
}
