<?php

declare(strict_types=1);

namespace App\Inventories;

use App\Inventories\Fields\FieldType;
use App\Inventories\Fields\FieldTypes;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use JsonException;

/**
 * The text an item is searched by: kept on the item (`items.search_text`) so that a search is one `LIKE` per word on
 * one column, the same on PostgreSQL, MySQL and MariaDB, whatever the case and the accents.
 *
 * It is built from the values the item holds, in the order of the fields. Each type of field says what its value adds
 * ({@see FieldType::searchText()}: the texts, the tags, the labels of the choices; a number or a date adds
 * nothing), each part goes through {@see SearchText::normalize()}, and the parts are joined by a line break, which no
 * word of a search can hold, so that a word never matches across two values. An item that adds nothing has no text (null).
 *
 * The text is written with the values ({@see Items}) and rebuilt, in the transaction of the change, when a change to a
 * field alters what its values say ({@see InventoryFields}). {@see self::refresh()} rebuilds it for a whole inventory:
 * `inventaire:upgrade` does so for the items that existed before the column did and whenever the form of the text changes
 * ({@see SearchText::VERSION}, {@see DerivedData}). It is always written with the query builder, so that the items keep
 * their `updated_at`: the item did not change, the way it is found did.
 */
final class ItemSearchText
{
    /** Items read at a time when the text of a whole inventory is rebuilt. */
    private const CHUNK = 500;

    /** Separates the parts of the text; never produced by {@see SearchText::normalize()}. */
    private const SEPARATOR = "\n";

    /**
     * Gets the database and the registry of types, which knows what each value says.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly FieldTypes $types,
    ) {}

    /**
     * The text of an item that holds these values, or null when nothing in them is searched.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory, in order.
     * @param  array<string, mixed>  $values  What the item holds, keyed by the ULID of the field.
     */
    public function build(Collection $fields, array $values): ?string
    {
        $parts = [];

        foreach ($fields as $field) {
            $type = $this->types->of($field);

            if ($type === null || ! array_key_exists($field->ulid, $values)) {
                continue;
            }

            $part = SearchText::normalize($type->searchText($values[$field->ulid], $field) ?? '');

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : implode(self::SEPARATOR, $parts);
    }

    /**
     * Rebuilds the text of the items of the inventory, a chunk at a time, writing only the ones whose text changes.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory, in order.
     * @param  bool  $onlyMissing  Whether to rebuild only the items that have no text (the others are taken as right).
     *
     * @throws JsonException
     */
    public function refresh(Inventory $inventory, Collection $fields, bool $onlyMissing = false): void
    {
        $this->db->table('items')
            ->where('inventory_id', $inventory->getKey())
            ->when($onlyMissing, fn (QueryBuilder $query): QueryBuilder => $query->whereNull('search_text'))
            ->chunkById(self::CHUNK, function (Collection $rows) use ($fields): void {
                foreach ($rows as $row) {
                    $values = json_decode((string) $row->values, true, 512, JSON_THROW_ON_ERROR);
                    $text = $this->build($fields, is_array($values) ? $values : []);

                    if ($text !== $row->search_text) {
                        $this->db->table('items')->where('id', $row->id)->update(['search_text' => $text]);
                    }
                }
            }, 'id');
    }

    /**
     * Keeps the items whose text holds every word of what was typed, in whatever order, wherever in the text.
     *
     * The words are in the form of the text ({@see SearchText::words()}), each looked for with `LIKE ... ESCAPE '!'` (the
     * same on the three engines), in which the wildcards a person may type (`%`, `_`) and the escape character are
     * escaped: they stand for themselves.
     * Both sides are already plain, so the collation of the column hardly matters, but it is not nothing: on MySQL and
     * MariaDB `LIKE` follows it (`utf8mb4_unicode_ci`), and it may take two characters for one that PHP and PostgreSQL
     * keep apart (`ς` and `σ`, which {@see SearchText::normalize()} therefore writes the same). A collation only ever
     * equates more, never less: a word of the text is always found by itself.
     * Nothing is filtered when nothing is left to look for (an empty text, or white space only).
     *
     * @param  EloquentBuilder<Item>|Relation<Item, Inventory, *>  $query  A query on the items.
     */
    public function restrict(EloquentBuilder|Relation $query, string $typed): void
    {
        foreach (SearchText::words($typed) as $word) {
            // The identifiers are fixed and need no quoting on any of the three engines. The escape character is the one of
            // SearchText::ESCAPE, which the pattern uses.
            $query->whereRaw("items.search_text LIKE ? ESCAPE '!'", [SearchText::pattern($word)]);
        }
    }
}
