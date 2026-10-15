<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Inventories\ItemValues;
use App\Inventories\SearchText;
use App\Models\InventoryField;
use Illuminate\Validation\ValidationException;

/**
 * One type of field: what a value of it looks like, how it is checked, how it is stored and how it is given back.
 *
 * A type is a stateless object found in the registry ({@see FieldTypes}) by its key; the field it works on is passed to
 * each method, because the options of the field (the maximum length of a text, the decimals of a number, the choices
 * of a select) change the answer. Adding a type is adding a class and listing it in the registry: nothing else in the
 * application knows the types one by one.
 *
 * What happens to a value a person sends, in this order (see {@see ItemValues}):
 *
 * 1. {@see self::prepare()} cleans it up (surrounding spaces, an empty tag, the line breaks of another system);
 * 2. {@see self::isEmpty()} says whether nothing is left: a value that is empty clears the field (and is refused when
 *    the field is required), it is never stored;
 * 3. the rules of {@see self::valueRules()} check what is left, and refuse it with a message that names the field;
 * 4. {@see self::normalize()} gives the canonical form that is stored.
 *
 * And what is read back goes through {@see self::present()}, which drops what no longer makes sense (a choice that
 * was deleted from the field since the value was written). What a value adds to the text a person can search an item by
 * is told by {@see self::searchText()}.
 *
 * Options follow the same split: {@see self::optionRules()} checks each option one by one as it is sent, and
 * {@see self::normalizeOptions()} fills in the defaults, puts them in canonical form and judges the options together.
 */
interface FieldType
{
    /**
     * The word that identifies the type in the API and in the database (`text`, `number`...). Never changes.
     */
    public function key(): string;

    /**
     * What the type is called for a person, in the language of the request.
     */
    public function label(): string;

    /**
     * The rules each option must follow *when it is sent*, by option name (nested names use dots and `*`:
     * `choices.*.label`). Every option is optional here: what is missing is the business of {@see self::normalizeOptions()},
     * which also fills in the defaults. An option that is not listed is refused.
     *
     * @param  array<string, mixed>|null  $previous  The options the field has now, or null while it is being created: a
     *                                               value the field already holds stays acceptable even if the rules
     *                                               would no longer let it be chosen (a currency that went out of use).
     * @return array<string, list<mixed>>
     */
    public function optionRules(?array $previous = null): array;

    /**
     * The options in canonical form, complete with their defaults, from the options that passed {@see self::optionRules()}.
     *
     * @param  array<string, mixed>  $options  The options of the field as they will be (the ones sent over the ones it had).
     * @param  array<string, mixed>|null  $previous  The options the field has now, or null while it is being created.
     * @return array<string, mixed>
     *
     * @throws ValidationException When options that are fine one by one do not fit together (a minimum above the maximum,
     *                             no choice at all). The keys are those of the request (`options.max`).
     */
    public function normalizeOptions(array $options, ?array $previous): array;

    /**
     * Whether changing the options of a field from `$previous` to `$options` can leave stored values that no longer fit
     * (a choice that was deleted, a rating above the new maximum). When it can, the values of the field are cleaned up in
     * the same transaction, each one through {@see self::present()}, which drops what no longer makes sense.
     *
     * The other changes (the decimals of a number, the currency of an amount, the length of a text, the format of a
     * barcode...) never touch what is stored: a value written under older options stays as it is.
     *
     * @param  array<string, mixed>  $previous  The options the field had.
     * @param  array<string, mixed>  $options  The options it has now, in canonical form.
     */
    public function mayInvalidateValues(array $previous, array $options): bool;

    /**
     * Whether changing the options of a field from `$previous` to `$options` can change the text its stored values add to
     * the search ({@see self::searchText()}): a choice that was renamed, or deleted, is no longer found by its old label.
     * When it can, the search text of the items that hold a value of the field is rebuilt in the same transaction.
     *
     * Most types take nothing from their options: they answer `false`. A new choice, a color, an order change nothing.
     *
     * @param  array<string, mixed>  $previous  The options the field had.
     * @param  array<string, mixed>  $options  The options it has now, in canonical form.
     */
    public function mayChangeSearchText(array $previous, array $options): bool;

    /**
     * Cleans up a value before it is judged. Leaves alone what it does not understand: the rules refuse that.
     */
    public function prepare(mixed $value, InventoryField $field): mixed;

    /**
     * Whether a value stands for "nothing": the field is then cleared instead of holding it. `false` and `0` are values.
     */
    public function isEmpty(mixed $value): bool;

    /**
     * The rules a value must follow once it is prepared and known not to be empty.
     *
     * Messages use `:attribute`, which stands for the name of the field.
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array;

    /**
     * The canonical form of a value that passed {@see self::valueRules()}: what is stored, whatever the way it was sent
     * (a decimal string with the decimals of the field, a date in UTC...).
     */
    public function normalize(mixed $value, InventoryField $field): mixed;

    /**
     * A stored value as it is given back. Returns an empty value (null or an empty list) for what must not be shown.
     */
    public function present(mixed $stored, InventoryField $field): mixed;

    /**
     * The text a stored value adds to what a person can search its item by, or null when the type is not searched (a
     * number, a date, a rating, a color, a yes or no: nobody looks for an item by typing "12.5" or "2026-03-01").
     *
     * It is the text as a person reads it: a text, an address, a list of tags, and for a choice its *label* (the value
     * holds the id of the choice). It is not yet in the form that is searched, which is
     * {@see SearchText::normalize()}'s to give: the caller does it, for every type alike. A value the
     * field does not understand (a choice that is gone) adds nothing.
     */
    public function searchText(mixed $stored, InventoryField $field): ?string;
}
