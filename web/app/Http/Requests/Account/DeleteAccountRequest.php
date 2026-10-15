<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Services\OneTimeCode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The code that confirms the deletion of the account.
 */
final class DeleteAccountRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:'.OneTimeCode::LENGTH],
        ];
    }

    /**
     * The code as typed, without the spaces people paste around or inside it.
     */
    public function code(): string
    {
        return $this->string('code')->toString();
    }

    /**
     * Removes the spaces from the code before it is validated.
     */
    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if (is_string($code)) {
            $this->merge(['code' => preg_replace('/\s+/', '', $code)]);
        }
    }
}
