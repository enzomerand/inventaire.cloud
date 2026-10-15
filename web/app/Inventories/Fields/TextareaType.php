<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use App\Rules\MultiLineText;

/**
 * A long text with line breaks: a description, a note. At most 10000 characters.
 *
 * Line breaks (and tabs) are kept; the line breaks of every system are stored as "\n". The other control characters,
 * the separators of lines and paragraphs, and the text direction controls are refused (see {@see MultiLineText}).
 */
final class TextareaType extends AbstractFieldType
{
    /** Longest text, in characters. */
    public const MAX_LENGTH = 10000;

    public function key(): string
    {
        return 'textarea';
    }

    public function label(): string
    {
        return __('Long text');
    }

    /**
     * Writes every line break as "\n" (so the length is the same whatever system the text comes from), then trims.
     */
    public function prepare(mixed $value, InventoryField $field): mixed
    {
        return is_string($value) ? MultiLineText::normalize($value) : $value;
    }

    /**
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return ['string', 'max:'.self::MAX_LENGTH, new MultiLineText];
    }
}
