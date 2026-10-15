<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Settings\InstanceSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The settings of the instance an administrator changes: a partial update. Each setting is optional, and the ones
 * that are not sent keep what they hold; a request that sends none of them changes nothing and is refused.
 */
final class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            InstanceSettings::REGISTRATION_OPEN => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * Refuses a request that sends no setting at all (an error under `settings`).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(array_keys($this->rules()))) {
                    $validator->errors()->add('settings', __('Send at least one setting.'));
                }
            },
        ];
    }

    /**
     * The settings that were sent, with their new values: only those, so that the others are left alone.
     *
     * @return array{registration_open?: bool}
     */
    public function settings(): array
    {
        $settings = [];

        if ($this->has(InstanceSettings::REGISTRATION_OPEN)) {
            $settings[InstanceSettings::REGISTRATION_OPEN] = $this->boolean(InstanceSettings::REGISTRATION_OPEN);
        }

        return $settings;
    }
}
