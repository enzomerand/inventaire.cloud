<?php

declare(strict_types=1);

namespace App\Inventories;

use App\Database\JsonValues;
use App\Http\Requests\Inventories\ListItemsRequest;
use App\Inventories\Fields\ChoiceType;
use App\Inventories\Fields\Decimal;
use App\Inventories\Fields\DecimalType;
use App\Inventories\Fields\FieldType;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\NumberType;
use App\Inventories\Fields\QueryKind;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Sorts, filters and totals the items of an inventory as a list asks for them, against the fields the inventory has.
 *
 * What the list asks for ({@see ListItemsRequest}) is read here and checked against the fields: nothing the person typed
 * reaches the SQL but as a bound value, and the only identifiers that do are the ULIDs of fields the inventory has. Every
 * mistake is reported at once, as a `422` whose error keys name what is wrong (`sort`, `direction`, `filter.<ulid>`,
 * `filter.<ulid>.<operator>` and `totals.<position>`). The SQL itself, which differs from one engine to another, is
 * {@see JsonValues}'s.
 *
 * **Sort**: `sort` is `created_at`, `updated_at` or the ULID of a field, `direction` is `asc` (the default) or `desc`
 * (both read in any case).
 * The way a value is sorted comes from the kind of its field ({@see QueryKind}): text with the collation of the
 * connection, numbers as numbers, dates, moments, times and yes/no as their fixed-width text, a choice by its place in the
 * choices of the field. An item with no value for the field always comes last, whichever the direction, and so does one
 * whose choice the field no longer has. Items that sort alike come in the order of their id (in the same direction), so
 * that a page never repeats or skips an item. Without `sort`, the newest item comes first (and `direction` alone changes
 * nothing). A field that holds several values (several choices, tags) cannot be sorted. There is one sort key, on purpose:
 * a second one would double the number of expressions the database orders by, and the id already breaks every tie.
 *
 * **Filter**: `filter[<ulid>][<operator>]`, the conditions of all the fields and the search words all having to hold.
 * `empty` (true or false) is understood by every kind and tells whether the field holds no value. A number takes `min`
 * and `max` (decimal strings, both included), a date, moment or time takes `min` and `max` in the form it is stored in,
 * a yes/no takes `is`, a choice or several choices take `in` (a list of the ids of choices: an item holding any of them
 * matches). The words a text or a tag says are looked for with the search of the list, not with a filter.
 *
 * **Totals**: `totals[]` names the fields to add up, and only those are: {@see self::totals()} adds up the numbers and
 * amounts of the items the list matches, exactly, in one query, and costs one extraction of the JSON per item and per field
 * (seconds, at the quota of items and fields), so a list that does not ask for totals does not run it. A name that is no
 * field of the inventory, or a field that does not add up, is a mistake.
 *
 * The names of the fields are read in any case and used in lowercase, as the values are keyed.
 */
final class ItemQuery
{
    /** The conditions every kind of field understands. */
    private const EMPTY = 'empty';

    /**
     * Gets the database, whose engine decides the SQL, and the registry of types, which tells how each field is queried.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly FieldTypes $types,
    ) {}

    /**
     * Restricts and orders the query as the list asks, and tells which totals it asks for.
     *
     * @param  EloquentBuilder<Item>|Relation<Item, Inventory, *>  $items  A query on the items of one inventory.
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory.
     * @param  array<array-key, mixed>  $input  What the list asks for: `sort`, `direction`, `filter` and `totals`; the rest is ignored.
     * @return list<string> The fields to add up ({@see self::totals()}): their ULIDs in lowercase, each once, in the order they were asked for. Empty when the list asks for no total.
     *
     * @throws ValidationException With every mistake found; nothing is run before.
     */
    public function apply(EloquentBuilder|Relation $items, Collection $fields, array $input): array
    {
        $errors = new MessageBag;
        $queryable = $this->queryable($fields);
        $json = $this->json();

        $filter = $input['filter'] ?? null;

        if (is_array($filter)) {
            $this->filter($items, $json, $queryable, $filter, $errors);
        }

        $sort = $input['sort'] ?? null;
        $direction = $input['direction'] ?? 'asc';
        $direction = is_string($direction) ? strtolower($direction) : $direction;

        if ($direction !== 'asc' && $direction !== 'desc') {
            $errors->add('direction', __('The direction must be asc or desc.'));
            $direction = 'asc';
        }

        if (is_string($sort) && $sort !== '') {
            $this->sort($items, $json, $queryable, strtolower($sort), $direction, $errors);
        }

        $totals = $this->requestedTotals($queryable, $input['totals'] ?? null, $errors);

        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->messages());
        }

        if (! is_string($sort) || $sort === '') {
            $items->orderByDesc('items.id');
        }

        return $totals;
    }

    /**
     * The total of each of these fields (see {@see FieldType::summable()}: the numbers and amounts) over the items the query
     * matches: added exactly in one aggregate query, as a decimal string keyed by the ULID of the field.
     *
     * A total has at least the decimals of its field, and more when values stored under older options have more. It is
     * zero (`"0.00"`) when no item holds a value. A key that is no field of the inventory, or that does not add up, is
     * left out (the keys {@see self::apply()} returns never are).
     *
     * @param  EloquentBuilder<Item>|Relation<Item, Inventory, *>  $items  The query the list runs, whatever its order: only the matching items count, not the page.
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory.
     * @param  list<string>  $keys  The fields to add up, by ULID in lowercase.
     * @return array<string, string>
     *
     * @throws LogicException When the database answers with a total that is not a decimal number.
     */
    public function totals(EloquentBuilder|Relation $items, Collection $fields, array $keys): array
    {
        $summable = $this->summable($this->queryable($fields));
        $summed = [];

        foreach ($keys as $key) {
            if (isset($summable[$key])) {
                $summed[$key] = $summable[$key];
            }
        }

        if ($summed === []) {
            return [];
        }

        $sums = $this->json()->sums(($items instanceof Relation ? $items->getQuery() : $items)->toBase(), array_keys($summed));
        $totals = [];

        foreach ($summed as $key => ['field' => $field, 'type' => $type]) {
            // No sum (null) is no row holding a value: zero. A sum that is not a number is not that, and not to be hidden.
            $sum = $sums[$key] ?? null;
            $sum = $sum === null ? Decimal::tryFrom('0') : Decimal::tryFrom(is_string($sum) || is_int($sum) ? (string) $sum : null);
            $places = $type instanceof DecimalType ? $type->decimals($field) : 0;

            if ($sum === null) {
                throw new LogicException("The database gave a total for the field {$key} that is not a decimal number.");
            }

            $totals[$field->ulid] = $sum->format(max($places, $sum->scale()));
        }

        return $totals;
    }

    /**
     * Applies the `filter` of the list.
     *
     * @param  EloquentBuilder<Item>|Relation<Item, Inventory, *>  $items
     * @param  array<string, array{field: InventoryField, type: FieldType}>  $queryable
     * @param  array<array-key, mixed>  $filter
     * @param  MessageBag  $errors  Where the mistakes are put, by error key.
     */
    private function filter(EloquentBuilder|Relation $items, JsonValues $json, array $queryable, array $filter, MessageBag $errors): void
    {
        $seen = [];

        foreach ($filter as $name => $conditions) {
            $key = strtolower((string) $name);
            $at = "filter.{$key}";

            if (isset($seen[$key])) {
                $errors->add($at, __('This field is given more than once.'));

                continue;
            }

            $seen[$key] = true;

            if (! isset($queryable[$key])) {
                $errors->add($at, __('This field does not exist in this inventory.'));

                continue;
            }

            if (! is_array($conditions)) {
                $errors->add($at, __('The conditions on a field must be given by operator.'));

                continue;
            }

            ['field' => $field, 'type' => $type] = $queryable[$key];

            foreach ($conditions as $operator => $value) {
                $operator = (string) $operator;

                if (! in_array($operator, $this->operators($type->queryKind()), true)) {
                    $errors->add("{$at}.{$operator}", __('This filter does not apply to this kind of field.'));

                    continue;
                }

                $this->condition($items, $json, $field, $type, $operator, $value, "{$at}.{$operator}", $errors);
            }
        }
    }

    /**
     * Applies one condition of a filter, or records why its value is refused.
     *
     * @param  EloquentBuilder<Item>|Relation<Item, Inventory, *>  $items
     * @param  MessageBag  $errors  Where the mistakes are put, by error key.
     */
    private function condition(EloquentBuilder|Relation $items, JsonValues $json, InventoryField $field, FieldType $type, string $operator, mixed $value, string $at, MessageBag $errors): void
    {
        $key = $field->ulid;
        $kind = $type->queryKind();

        if ($operator === self::EMPTY) {
            $empty = $this->boolean($value);

            if ($empty === null) {
                $errors->add($at, __('The value must be true or false.'));

                return;
            }

            $json->wherePresent($items, $key, ! $empty);

            return;
        }

        // Text and tags only answer `empty`, which was handled above: no other kind of field reaches a case.
        switch ($kind) {
            case QueryKind::Number:
                $bound = $this->decimal($value);

                if ($bound === null) {
                    $errors->add($at, __('The value must be a decimal number below 1000000000000000 in absolute value, with at most 6 decimals.'));

                    return;
                }

                $json->whereNumber($items, $key, $operator === 'min' ? '>=' : '<=', $bound);

                return;
            case QueryKind::Temporal:
                $bound = $this->storedForm($type, $field, $value);

                if ($bound === null) {
                    $errors->add($at, __('The value must be written the way it is stored: YYYY-MM-DD for a date, YYYY-MM-DDTHH:MM:SSZ (in UTC) for a date and time, HH:MM for a time.'));

                    return;
                }

                $json->whereText($items, $key, $operator === 'min' ? '>=' : '<=', $bound);

                return;
            case QueryKind::Boolean:
                $is = $this->boolean($value);

                if ($is === null) {
                    $errors->add($at, __('The value must be true or false.'));

                    return;
                }

                $json->whereBoolean($items, $key, $is);

                return;
            case QueryKind::Choice:
            case QueryKind::Choices:
                $ids = $this->choices($field, $value, $at, $errors);

                if ($ids === null) {
                    return;
                }

                if ($kind === QueryKind::Choice) {
                    $json->whereTextIn($items, $key, $ids);
                } else {
                    $json->whereContainsAny($items, $key, $ids);
                }

                return;
        }
    }

    /**
     * The fields the list asks to add up, once each: the keys of `totals`, in lowercase, or the reason why one is refused.
     *
     * @param  array<string, array{field: InventoryField, type: FieldType}>  $queryable
     * @param  MessageBag  $errors  Where the mistakes are put, by error key (`totals.<position>`).
     * @return list<string>
     */
    private function requestedTotals(array $queryable, mixed $asked, MessageBag $errors): array
    {
        if (! is_array($asked)) {
            return [];
        }

        $summable = $this->summable($queryable);
        $keys = [];

        foreach ($asked as $position => $name) {
            $key = is_string($name) ? strtolower($name) : '';

            if (! isset($queryable[$key])) {
                $errors->add("totals.{$position}", __('This field does not exist in this inventory.'));
            } elseif (! isset($summable[$key])) {
                $errors->add("totals.{$position}", __('This field cannot be added up.'));
            } else {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }

    /**
     * The fields among these that add up (see {@see FieldType::summable()}).
     *
     * @param  array<string, array{field: InventoryField, type: FieldType}>  $queryable
     * @return array<string, array{field: InventoryField, type: FieldType}>
     */
    private function summable(array $queryable): array
    {
        return array_filter($queryable, fn (array $entry): bool => $entry['type']->summable());
    }

    /**
     * Orders the query by a column of the items or by the values of a field, or records why it cannot.
     *
     * @param  EloquentBuilder<Item>|Relation<Item, Inventory, *>  $items
     * @param  array<string, array{field: InventoryField, type: FieldType}>  $queryable
     * @param  'asc'|'desc'  $direction
     * @param  MessageBag  $errors  Where the mistakes are put, by error key.
     */
    private function sort(EloquentBuilder|Relation $items, JsonValues $json, array $queryable, string $sort, string $direction, MessageBag $errors): void
    {
        if ($sort === 'created_at' || $sort === 'updated_at') {
            $items->orderBy("items.{$sort}", $direction)->orderBy('items.id', $direction);

            return;
        }

        if (! isset($queryable[$sort])) {
            $errors->add('sort', __('This field does not exist in this inventory.'));

            return;
        }

        ['field' => $field, 'type' => $type] = $queryable[$sort];
        $kind = $type->queryKind();

        if (! $kind->sortable()) {
            $errors->add('sort', __('This field cannot be sorted.'));

            return;
        }

        match ($kind) {
            QueryKind::Text => $json->orderByCollatedText($items, $sort, $direction),
            QueryKind::Number => $json->orderByNumber($items, $sort, $direction),
            QueryKind::Choice => $json->orderByPosition($items, $sort, array_column(ChoiceType::choicesOf($field->options), 'id'), $direction),
            default => $json->orderByText($items, $sort, $direction),
        };

        $items->orderBy('items.id', $direction);
    }

    /**
     * The ids of the choices a filter asks for, or null (with the reason recorded) when the value is not a non-empty list
     * of choices of the field.
     *
     * @param  MessageBag  $errors  Where the mistakes are put, by error key.
     * @return list<string>|null
     */
    private function choices(InventoryField $field, mixed $value, string $at, MessageBag $errors): ?array
    {
        if (! is_array($value) || $value === []) {
            $errors->add($at, __('The value must be a list of choices of this field.'));

            return null;
        }

        $known = array_column(ChoiceType::choicesOf($field->options), 'id');
        $ids = [];

        foreach ($value as $id) {
            if (! is_string($id) || ! in_array($id, $known, true)) {
                $errors->add($at, __('This choice does not exist in this field.'));

                return null;
            }

            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * The fields the list can sort and filter by, keyed by their lowercase ULID: the ones whose type this version knows.
     *
     * @param  Collection<int, InventoryField>  $fields
     * @return array<string, array{field: InventoryField, type: FieldType}>
     */
    private function queryable(Collection $fields): array
    {
        $queryable = [];

        foreach ($fields as $field) {
            $type = $this->types->of($field);

            if ($type !== null) {
                $queryable[strtolower($field->ulid)] = ['field' => $field, 'type' => $type];
            }
        }

        return $queryable;
    }

    /**
     * The conditions a filter can put on a kind of field.
     *
     * @return list<string>
     */
    private function operators(QueryKind $kind): array
    {
        return match ($kind) {
            QueryKind::Number, QueryKind::Temporal => [self::EMPTY, 'min', 'max'],
            QueryKind::Boolean => [self::EMPTY, 'is'],
            QueryKind::Choice, QueryKind::Choices => [self::EMPTY, 'in'],
            QueryKind::Text, QueryKind::Tags => [self::EMPTY],
        };
    }

    /**
     * A yes or no as a query string writes it (`true`, `false`, `1`, `0`) or as JSON does; null for anything else.
     */
    private function boolean(mixed $value): ?bool
    {
        return match (is_string($value) ? strtolower($value) : $value) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false' => false,
            default => null,
        };
    }

    /**
     * A bound for a number in canonical form (`-12.5`), or null when it is not a decimal number below 1e15 in absolute
     * value with at most 6 decimals.
     */
    private function decimal(mixed $value): ?string
    {
        $number = is_string($value) || is_int($value) ? Decimal::tryFrom($value) : null;

        return $number !== null && $number->fits() && $number->scale() <= NumberType::MAX_DECIMALS ? $number->format() : null;
    }

    /**
     * A bound for a date, a moment or a time, when it is written exactly the way the type stores it (the rules of the
     * type accept it and its canonical form is itself: a moment is in UTC, to the second), or null.
     */
    private function storedForm(FieldType $type, InventoryField $field, mixed $value): ?string
    {
        if (! is_string($value) || ! Validator::make(['value' => $value], ['value' => $type->valueRules($field)])->passes()) {
            return null;
        }

        return $type->normalize($value, $field) === $value ? $value : null;
    }

    /**
     * The reader of the JSON values, for the engine of the connection.
     */
    private function json(): JsonValues
    {
        return new JsonValues($this->db->connection());
    }
}
