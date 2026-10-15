<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use App\Rules\SingleLineText;
use Closure;

/**
 * A long text with line breaks: a description, a note. At most 10000 characters.
 *
 * Line breaks (and tabs) are kept; the line breaks of every system are stored as "\n". The other control characters,
 * the separators of lines and paragraphs, and the text direction controls are refused, for the reasons given at
 * {@see SingleLineText}.
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
        return is_string($value)
            ? SingleLineText::trim(str_replace(["\r\n", "\r"], "\n", $value))
            : $value;
    }

    /**
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return ['string', 'max:'.self::MAX_LENGTH, function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            // A line break and a tab are allowed; any other control character, line or paragraph separator is not.
            if (preg_match('/[^\P{Cc}\n\t]|[\p{Zl}\p{Zp}]/u', $value) !== 0) {
                $fail(__('The :attribute must not contain control characters other than line breaks.'));

                return;
            }

            if (preg_match(SingleLineText::DIRECTION_CONTROLS, $value) === 1) {
                $fail(__('The :attribute must not contain characters that change the direction of the text.'));
            }
        }];
    }
}
