<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use App\Rules\SingleLineText;

/**
 * An email address of a contact or a supplier, kept as it was written (trimmed). At most 254 characters, the longest
 * an address can be.
 *
 * Unlike the address of an account (`App\Auth\EmailAddress`), it is not turned into a canonical form: it is data
 * about somebody else, shown back to the person who typed it, not a key to sign in with.
 */
final class EmailType extends AbstractFieldType
{
    /** Longest address, in characters. */
    public const MAX_LENGTH = 254;

    /**
     * The word that identifies the type in the API and in the database: `email`.
     */
    public function key(): string
    {
        return 'email';
    }

    /**
     * What the type is called for a person ("Email address"), in the language of the request.
     */
    public function label(): string
    {
        return __('Email address');
    }

    /**
     * How the list sorts and filters the values (text: sorted as text, filtered on being empty).
     */
    public function queryKind(): QueryKind
    {
        return QueryKind::Text;
    }

    /**
     * A text of at most 254 characters that is an email address, with no control character in it: the `email` rule lets
     * some through (a null character, which PostgreSQL cannot read back out of a JSON value, so that every list sorted or
     * filtered on the items would fail).
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return ['string', 'max:'.self::MAX_LENGTH, 'email', new SingleLineText];
    }

    /**
     * The address.
     */
    public function searchText(mixed $stored, InventoryField $field): ?string
    {
        return $this->stringSearchText($stored);
    }
}
