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
            InstanceSettings::INVITATION_SIGN_UP => ['sometimes', 'required', 'boolean'],
            InstanceSettings::SHARE_LINKS_ENABLED => ['sometimes', 'required', 'boolean'],
            InstanceSettings::PHOTO_ENTRY_ENABLED => ['sometimes', 'required', 'boolean'],
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
     * @return array{registration_open?: bool, invitation_sign_up?: bool, share_links_enabled?: bool, photo_entry_enabled?: bool}
     */
    public function settings(): array
    {
        $settings = [];

        foreach (array_keys($this->rules()) as $key) {
            if ($this->has($key)) {
                $settings[$key] = $this->boolean($key);
            }
        }

        return $settings;
    }
}
