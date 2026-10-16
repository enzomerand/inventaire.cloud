<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use Closure;

/**
 * A phone number as it is written by whoever dials it: an optional `+` first, then digits, spaces and the `().-`
 * people put between them, with at least 3 digits, in at most 32 characters. It is not reformatted: how a number is
 * grouped is a habit of its country.
 */
final class PhoneType extends AbstractFieldType
{
    /** Longest number, in characters. */
    public const MAX_LENGTH = 32;

    /** Fewest digits a number has. */
    public const MIN_DIGITS = 3;

    /**
     * The word that identifies the type in the API and in the database: `phone`.
     */
    public function key(): string
    {
        return 'phone';
    }

    /**
     * What the type is called for a person ("Phone number"), in the language of the request.
     */
    public function label(): string
    {
        return __('Phone number');
    }

    /**
     * How the list sorts and filters the values (text: sorted as text, filtered on being empty).
     */
    public function queryKind(): QueryKind
    {
        return QueryKind::Text;
    }

    /**
     * A text of at most 32 characters that is a phone number, with at least 3 digits.
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return ['string', 'max:'.self::MAX_LENGTH, function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)
                || preg_match('/^\+?[0-9 ().\-]+$/D', $value) !== 1
                || preg_match_all('/[0-9]/', $value) < self::MIN_DIGITS) {
                $fail(__('The :attribute must be a phone number: digits, spaces and the characters + ( ) . -, with at least 3 digits.'));
            }
        }];
    }

    /**
     * The number as it was written.
     */
    public function searchText(mixed $stored, InventoryField $field): ?string
    {
        return $this->stringSearchText($stored);
    }
}
