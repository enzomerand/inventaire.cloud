<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Inventories\Templates\Template;
use App\Inventories\Templates\TemplateCatalogue;
use App\Inventories\Templates\TemplateValidator;
use App\Models\Inventory;
use App\Policies\InventoryPolicy;
use App\Rules\MultiLineText;
use App\Rules\SingleLineText;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The name and the description of an inventory, to create one in a workspace or to change one.
 *
 * Who may do it is decided before anything is looked at (see {@see InventoryPolicy}), so that somebody who may not
 * learns nothing from the validation: a stranger is told the workspace or the inventory does not exist. Creating needs
 * a name; changing takes whichever of the two is sent, and a name that is sent cannot be empty. Creating may also name a
 * template of the catalogue (`template`, its slug), which only a new inventory can do. The template is looked up once
 * for the request: the one the validation found is the one the inventory is made from, so that a template that leaves the
 * catalogue in between (its file removed, the cache emptied) can never give an inventory without its fields; it is a `422`.
 */
final class SaveInventoryRequest extends FormRequest
{
    /** Longest name, in characters. */
    public const NAME_MAX_LENGTH = 120;

    /** Longest description, in characters. */
    public const DESCRIPTION_MAX_LENGTH = 2000;

    /** The template of the request, once it was looked up (null when the request names none, or one that does not exist). */
    private ?Template $template = null;

    /** Whether the template of the request was looked up. */
    private bool $templateResolved = false;

    /**
     * Adding to a workspace or changing an inventory: the policy knows which roles may.
     */
    public function authorize(): Response
    {
        $inventory = $this->route('inventory');

        return $inventory instanceof Inventory
            ? Gate::inspect('update', $inventory)
            : Gate::inspect('create', [Inventory::class, $this->route('workspace')]);
    }

    /**
     * The name (needed to create one) and the description.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $creating = ! $this->route('inventory') instanceof Inventory;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:'.self::NAME_MAX_LENGTH, new SingleLineText],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH, new MultiLineText],
            // The slug of a template of the catalogue (`GET /api/v1/templates`): the inventory is made with its fields.
            'template' => $creating
                ? ['bail', 'sometimes', 'nullable', 'string', 'max:'.TemplateValidator::SLUG_MAX_LENGTH, function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || $this->lookUpTemplate($value) === null) {
                        $fail(__('This template does not exist.'));
                    }
                }]
                : ['prohibited'],
        ];
    }

    /**
     * The template the inventory is to be made from, or null for an empty inventory (the request names none).
     *
     * It is the one the validation found, kept for the rest of the request. A template that is named but cannot be found is
     * not an empty inventory: it is the same `422` as the validation gives.
     *
     * @throws ValidationException When the request names a template the catalogue does not have.
     */
    public function template(): ?Template
    {
        $slug = $this->input('template');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return $this->lookUpTemplate($slug) ?? throw ValidationException::withMessages(['template' => [__('This template does not exist.')]]);
    }

    /**
     * Looks the template up in the catalogue the first time it is asked for, and gives the same answer afterwards.
     */
    private function lookUpTemplate(string $slug): ?Template
    {
        if (! $this->templateResolved) {
            $this->template = app(TemplateCatalogue::class)->find($slug);
            $this->templateResolved = true;
        }

        return $this->template;
    }

    /**
     * The attributes that were sent, validated and trimmed: a description that was left out is not in the list (it keeps
     * its value), one sent as null or empty is (it is cleared).
     *
     * @return array{name?: string, description?: string|null}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('name')) {
            $changes['name'] = $this->string('name')->toString();
        }

        if ($this->has('description')) {
            $description = $this->input('description');
            $changes['description'] = is_string($description) && $description !== '' ? $description : null;
        }

        return $changes;
    }

    /**
     * Trims the texts before they are validated, and writes the name in one Unicode form (see
     * {@see SingleLineText::normalize()}), so that a name made of spaces counts as missing and a description made of
     * spaces as none, whatever the framework's own trimming does.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $description = $this->input('description');

        $this->merge([
            ...is_string($name) ? ['name' => SingleLineText::normalize($name)] : [],
            ...is_string($description) ? ['description' => MultiLineText::normalize($description)] : [],
        ]);
    }
}
