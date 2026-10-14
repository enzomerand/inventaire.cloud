<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Auth\EmailAddress;
use App\Services\OneTimeCode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An address, the code that was sent to it and, for an address without an
 * account yet, the name of the person.
 */
final class VerifyLoginCodeRequest extends FormRequest
{
    /**
     * Anybody holding a valid code may use it.
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
            'code' => ['required', 'string', 'digits:'.OneTimeCode::LENGTH],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The address in its canonical form ({@see EmailAddress::normalize()}).
     */
    public function email(): string
    {
        return EmailAddress::normalize($this->string('email')->toString());
    }

    /**
     * The code as typed, without the spaces people paste around or inside it.
     */
    public function code(): string
    {
        return $this->string('code')->toString();
    }

    /**
     * The name given for a new account, or null when there is none.
     */
    public function name(): ?string
    {
        $name = $this->string('name')->trim()->toString();

        return $name === '' ? null : $name;
    }

    /**
     * Cleans the input before it is validated: canonical address, no spaces in the code.
     */
    protected function prepareForValidation(): void
    {
        $clean = [];

        if (is_string($this->input('email'))) {
            $clean['email'] = EmailAddress::normalize($this->input('email'));
        }

        if (is_string($this->input('code'))) {
            $clean['code'] = preg_replace('/\s+/', '', $this->input('code'));
        }

        $this->merge($clean);
    }
}
