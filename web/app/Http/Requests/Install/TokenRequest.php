<?php

declare(strict_types=1);

namespace App\Http\Requests\Install;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the setup token submitted to unlock the wizard.
 */
final class TokenRequest extends FormRequest
{
    /**
     * Anyone who can reach the form may try; the throttle limits the attempts.
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
        return ['token' => ['required', 'string', 'max:255']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['token' => __('setup token')];
    }
}
