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

    /**
     * The word that identifies the type in the API and in the database: `text`.
     */
    public function key(): string
    {
        return 'text';
    }

    /**
     * What the type is called for a person ("Text"), in the language of the request.
     */
    public function label(): string
    {
        return __('Text');
    }

    /**
     * How the list sorts and filters the values (text: sorted as text, filtered on being empty).
     */
    public function queryKind(): QueryKind
    {
        return QueryKind::Text;
    }

    /**
     * The longest text the field accepts.
     *
     * @param  array<string, mixed>|null  $previous
     * @return array<string, list<mixed>>
     */
    public function optionRules(?array $previous = null): array
    {
        return [
            'max_length' => ['nullable', 'integer', 'between:1,'.self::LIMIT],
        ];
    }

    /**
     * Fills in the longest text.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function normalizeOptions(array $options, ?array $previous): array
    {
        return ['max_length' => (int) ($options['max_length'] ?? self::DEFAULT_MAX_LENGTH)];
    }

    /**
     * A line of text, at most as long as the field allows.
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return ['string', 'max:'.(int) $field->option('max_length', self::DEFAULT_MAX_LENGTH), new SingleLineText];
    }

    /**
     * The text.
     */
    public function searchText(mixed $stored, InventoryField $field): ?string
    {
        return $this->stringSearchText($stored);
    }
}
