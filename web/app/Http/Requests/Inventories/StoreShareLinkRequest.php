<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Inventories\Sharing\ShareLinks;
use App\Policies\InventoryPolicy;
use App\Rules\SingleLineText;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * What an owner gives to make a share link: an optional label, whether the files are shown, and how long the link works. Owners only (see
 * {@see InventoryPolicy::share()}); who may is decided before the body is looked at, so that somebody who may not learns nothing from the
 * validation.
 *
 * - `label`: optional, on one line, at most 80 characters, trimmed;
 * - `include_files`: a boolean, true when it is left out;
 * - `expires_in_days`: 7, 30, 90 or 365; JSON `null` for a link that never expires; 30 when it is left out. An empty text is not `null`: the
 *   framework turns every empty text into `null` before the request is read (`ConvertEmptyStringsToNull`), so the body as it was sent is
 *   looked at, and `""` is refused instead of making a link that never expires.
 */
final class StoreShareLinkRequest extends FormRequest
{
    /**
     * Sharing an inventory is for the owners of its workspace.
     */
    public function authorize(): Response
    {
        return Gate::inspect('share', $this->route('inventory'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:'.ShareLinks::LABEL_MAX_LENGTH, new SingleLineText],
            'include_files' => ['sometimes', 'boolean'],
            'expires_in_days' => ['nullable', 'integer', Rule::in(ShareLinks::LIFETIMES)],
        ];
    }

    /**
     * What the owner calls the link, trimmed: none when it is absent, null or empty.
     */
    public function label(): ?string
    {
        $label = $this->validated('label');

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * Whether the files and photos are shown through the link: they are, unless the request says otherwise.
     */
    public function includeFiles(): bool
    {
        return $this->boolean('include_files', true);
    }

    /**
     * Days the link works for: the number asked for, null for ever (JSON `null` sent), or the usual 30 when the field is left out.
     */
    public function expiresInDays(): ?int
    {
        if (! $this->exists('expires_in_days')) {
            return ShareLinks::DEFAULT_LIFETIME_DAYS;
        }

        $days = $this->input('expires_in_days');

        return $days === null ? null : (int) $days;
    }

    /**
     * Refuses a lifetime that is an empty text, which the framework has turned into `null`: only a `null` the body says in JSON means
     * "for ever". A form cannot say it at all (it has no `null`), so an empty field there is refused as well.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->exists('expires_in_days') && $this->input('expires_in_days') === null && ! $this->sendsJsonNull()) {
                // The same failure, with the same message, as a lifetime that is not one of those offered.
                $validator->addFailure('expires_in_days', 'In');
            }
        });
    }

    /**
     * Whether the body, as it was sent, has `"expires_in_days": null` in JSON.
     */
    private function sendsJsonNull(): bool
    {
        if (! $this->isJson()) {
            return false;
        }

        $body = json_decode($this->getContent(), true);

        return is_array($body) && array_key_exists('expires_in_days', $body) && $body['expires_in_days'] === null;
    }

    /**
     * Trims the label before it is validated, so that one made of spaces counts as empty, and one with spaces or invisible characters
     * around it (a paste can bring them along) is stored without them.
     */
    protected function prepareForValidation(): void
    {
        $label = $this->input('label');

        if (is_string($label)) {
            $this->merge(['label' => SingleLineText::normalize($label)]);
        }
    }
}
