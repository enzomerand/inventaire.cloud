<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;

/**
 * A web address: a product page, a manual. Only `http` and `https` are accepted, so that a link shown in the interface
 * can never run a script (`javascript:`) or open a file. At most 2048 characters.
 */
final class UrlType extends AbstractFieldType
{
    /** Longest address, in characters. */
    public const MAX_LENGTH = 2048;

    /**
     * The word that identifies the type in the API and in the database: `url`.
     */
    public function key(): string
    {
        return 'url';
    }

    /**
     * What the type is called for a person ("URL"), in the language of the request.
     */
    public function label(): string
    {
        return __('URL');
    }

    /**
     * A web address of at most 2048 characters, `http` or `https`.
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return ['string', 'max:'.self::MAX_LENGTH, 'url:http,https'];
    }
}
