<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use App\Rules\SingleLineText;

/**
 * A short text on one line: a reference, a name, a serial number. The longest it may be is an option.
 */
final class TextType extends AbstractFieldType
{
    /** The length a text may have when the field says nothing. */
    public const DEFAULT_MAX_LENGTH = 255;

    /** The longest a field may allow a text to be. */
    public const LIMIT = 1000;

    public function key(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return __('Text');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function optionRules(): array
    {
        return [
            'max_length' => ['nullable', 'integer', 'between:1,'.self::LIMIT],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function normalizeOptions(array $options, ?array $previous): array
    {
        return ['max_length' => (int) ($options['max_length'] ?? self::DEFAULT_MAX_LENGTH)];
    }

    /**
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return ['string', 'max:'.(int) $field->option('max_length', self::DEFAULT_MAX_LENGTH), new SingleLineText];
    }
}
