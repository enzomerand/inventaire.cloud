<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The settings of the instance an administrator changes: every one is required, so that a request says what the
 * instance is to be, not what to leave alone.
 */
final class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'registration_open' => ['required', 'boolean'],
        ];
    }

    /**
     * Whether new addresses can create an account.
     */
    public function registrationOpen(): bool
    {
        return $this->boolean('registration_open');
    }
}
