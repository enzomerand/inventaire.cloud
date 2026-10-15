<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Inventories\Fields\FieldType;
use App\Inventories\Fields\FieldTypes;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Policies\InventoryPolicy;
use App\Rules\SingleLineText;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A field of an inventory, to add one or to change one: its name, its type (only when adding: it cannot change), whether
 * it is required, and the options of its type.
 *
 * The rules for the options are the ones of the type: that is the type of the field being changed, or the type being
 * given to the one being added. An option the type does not have is refused (`options.<name>`), not ignored. What the
 * options mean together (a minimum above a maximum, no choice at all) is judged by the type when the field is saved.
 */
final class SaveFieldRequest extends FormRequest
{
    /** Longest name, in characters. */
    public const NAME_MAX_LENGTH = 120;

    /**
     * Managing the fields of an inventory: the policy knows which roles may (see {@see InventoryPolicy}).
     */
    public function authorize(): Response
    {
        $inventory = $this->route('inventory');

        return $inventory instanceof Inventory
            ? Gate::inspect('manageFields', $inventory)
            : Response::denyAsNotFound();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $field = $this->route('field');
        $creating = ! $field instanceof InventoryField;
        $types = app(FieldTypes::class);
        $type = $creating ? $types->find($this->sentType()) : $types->of($field);

        $rules = [
            'type' => $creating
                ? ['required', 'string', Rule::in($types->keys())]
                : ['sometimes', 'string', function (string $attribute, mixed $value, Closure $fail) use ($field): void {
                    if ($value !== $field->type) {
                        $fail(__('The type of a field cannot be changed.'));
                    }
                }],
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:'.self::NAME_MAX_LENGTH, new SingleLineText],
            'required' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'nullable', 'array'],
        ];

        if ($type !== null) {
            foreach ($type->optionRules() as $option => $optionRules) {
                $rules["options.{$option}"] = $optionRules;
            }
        }

        return $rules;
    }

    /**
     * The names the messages give to the options.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'options.max_length' => __('maximum length'),
            'options.decimals' => __('number of decimals'),
            'options.min' => __('minimum'),
            'options.max' => __('maximum'),
            'options.unit' => __('unit'),
            'options.currency' => __('currency'),
            'options.format' => __('format'),
            'options.choices' => __('choices'),
            'options.choices.*.id' => __('choice identifier'),
            'options.choices.*.label' => __('choice label'),
            'options.choices.*.color' => __('choice color'),
        ];
    }

    /**
     * Refuses the options the type does not have, one error each.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = $this->typeOfField();
            $options = $this->input('options');

            if ($type === null || ! is_array($options)) {
                return;
            }

            $known = array_map(fn (string $option): string => explode('.', $option)[0], array_keys($type->optionRules()));

            foreach (array_keys($options) as $option) {
                if (! in_array((string) $option, $known, true)) {
                    $validator->errors()->add("options.{$option}", __('This type of field does not have this option.'));
                }
            }
        }];
    }

    /**
     * The changes that were sent, validated: only the keys that were sent are in the list.
     *
     * @return array{name?: string, required?: bool, options?: array<string, mixed>}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('name')) {
            $changes['name'] = $this->string('name')->toString();
        }

        if ($this->has('required')) {
            $changes['required'] = $this->boolean('required');
        }

        if (is_array($this->input('options'))) {
            $changes['options'] = $this->array('options');
        }

        return $changes;
    }

    /**
     * Trims the name before it is validated, so that a name made of spaces counts as missing.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => SingleLineText::trim($name)]);
        }
    }

    /**
     * The type that was sent, or an empty text when it is missing or is not a text (the rules refuse it).
     */
    public function sentType(): string
    {
        $type = $this->input('type');

        return is_string($type) ? $type : '';
    }

    /**
     * The type whose options this request is about: the one being given to a new field, or the one the field has.
     */
    private function typeOfField(): ?FieldType
    {
        $field = $this->route('field');
        $types = app(FieldTypes::class);

        return $field instanceof InventoryField ? $types->of($field) : $types->find($this->sentType());
    }
}
