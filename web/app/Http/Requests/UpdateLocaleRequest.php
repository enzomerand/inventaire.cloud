<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a visitor's request to change the interface language.
 *
 * Only locales we ship translations for are accepted: storing an arbitrary
 * value in the session would make the translator silently fall back, and the
 * switcher would look broken.
 */
class UpdateLocaleRequest extends FormRequest
{
    /**
     * Anyone, signed in or not, may choose their language.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(config()->array('app.supported_locales'))],
        ];
    }
}
