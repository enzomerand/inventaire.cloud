<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\LocaleResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a visitor's request to change the interface language.
 *
 * Only locales we ship translations for are accepted: remembering an arbitrary
 * value would make the translator silently fall back, and the switcher would
 * look broken. Anyone, signed in or not, may choose their language, so there is
 * no authorization to check.
 */
class UpdateLocaleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(app(LocaleResolver::class)->supported())],
        ];
    }
}
