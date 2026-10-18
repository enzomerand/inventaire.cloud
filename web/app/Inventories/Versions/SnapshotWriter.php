<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

use App\Inventories\Exceptions\SnapshotTooLargeException;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\InventoryVersion;
use App\Models\Item;
use App\Models\Workspace;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use RuntimeException;
use stdClass;

/**
 * Writes the canonical JSON of what an inventory holds: the data of an official version, from which its PDF is written and whose
 * SHA-256 is its proof.
 *
 * ```
 * {"format": "inventaire.cloud/official-version", "format_version": 1,
 *  "version": {"id", "number", "label", "frozen_at"},
 *  "workspace": {"id", "name"}, "inventory": {"id", "name", "description"}, "author": {"name"},
 *  "fields": [{"id", "name", "type", "position", "required", "options"}],
 *  "items": [{"id", "values": {<ULID of the field>: value}, "created_at", "updated_at"}],
 *  "attachments": [{"id", "item_id", "field_id", "name", "mime", "size"}]}
 * ```
 *
 * The same inventory, frozen twice with nothing changed in between, gives the same bytes but for the version and the date. The text
 * is written the same way every time (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, no compression, one field, item and file to
 * a line), the keys of the values and of the options are sorted at every depth since MySQL and MariaDB reorder the keys of a JSON
 * column (and PostgreSQL does too, by length), the items come in the order of their id and the files in the order of theirs. The
 * values are the stored ones, not the ones the API presents: the document is what the inventory held, whatever this version of the
 * application would make of a choice that was deleted from its field, or of a type it does not know. The files themselves are not
 * in it, only who they belong to, their name, type and size.
 *
 * The document is written as a stream, a chunk of {@see self::CHUNK} rows at a time: memory does not grow with the number of items.
 * The caller holds the lock of the inventory, so that what is read is one state of it. The job that writes the PDF reads the whole file
 * into memory, so the writing stops as soon as the file would weigh more than `inventories.max_snapshot_bytes`
 * ({@see SnapshotTooLargeException}): the lock is not held for longer than it takes to reach the limit, and nothing is kept.
 */
final class SnapshotWriter
{
    /** The name of the shape of the document. */
    public const FORMAT = 'inventaire.cloud/official-version';

    /** The version of the shape of the document, raised when it changes in a way a reader would notice. */
    public const FORMAT_VERSION = 1;

    /** Rows read at a time. */
    private const CHUNK = 500;

    /** Bytes written so far to the file being written, to hold it to `inventories.max_snapshot_bytes`. */
    private int $written = 0;

    /** Readable and short: the text is not escaped. A character that is not valid UTF-8 fails the writing, since the data would not be what was held. */
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Gets the database, which the files are read from.
     */
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Writes the document.
     *
     * @param  string  $path  Where on this machine the document is written.
     * @param  InventoryVersion  $version  The version being frozen, not saved yet: its ULID, number, label, date and author's name.
     * @param  Workspace  $workspace  The workspace of the inventory.
     * @param  Inventory  $inventory  The inventory, locked.
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory, in the order they are shown.
     * @return int The number of items written.
     *
     * @throws RuntimeException When the file cannot be written.
     * @throws SnapshotTooLargeException When the document would weigh more than `inventories.max_snapshot_bytes`.
     */
    public function write(string $path, InventoryVersion $version, Workspace $workspace, Inventory $inventory, Collection $fields): int
    {
        $this->written = 0;
        $stream = fopen($path, 'wb');

        if ($stream === false) {
            throw new RuntimeException('The file of the version could not be opened.');
        }

        try {
            $this->put($stream, '{"format":'.json_encode(self::FORMAT, self::FLAGS).',"format_version":'.self::FORMAT_VERSION
                .",\n\"version\":".json_encode(['id' => $version->ulid, 'number' => $version->number, 'label' => $version->label, 'frozen_at' => $version->frozen_at->utc()->toIso8601String()], self::FLAGS)
                .",\n\"workspace\":".json_encode(['id' => $workspace->ulid, 'name' => $workspace->name], self::FLAGS)
                .",\n\"inventory\":".json_encode(['id' => $inventory->ulid, 'name' => $inventory->name, 'description' => $inventory->description], self::FLAGS)
                .",\n\"author\":".json_encode(['name' => $version->author_name], self::FLAGS)
                .",\n\"fields\":[\n");

            $this->put($stream, implode(",\n", $fields->map(fn (InventoryField $field): string => json_encode($this->field($field), self::FLAGS))->all()));
            $this->put($stream, "\n],\n\"items\":[\n");

            $items = 0;

            Item::query()
                ->select(['id', 'ulid', 'values', 'created_at', 'updated_at'])
                ->where('inventory_id', $inventory->getKey())
                ->chunkById(self::CHUNK, function (Collection $chunk) use ($stream, &$items): void {
                    foreach ($chunk as $item) {
                        $this->put($stream, ($items === 0 ? '' : ",\n").json_encode($this->item($item), self::FLAGS));
                        $items++;
                    }
                });

            $this->put($stream, "\n],\n\"attachments\":[\n");

            $files = 0;

            $this->db->table('attachments')
                ->join('items', 'items.id', '=', 'attachments.item_id')
                ->leftJoin('inventory_fields', 'inventory_fields.id', '=', 'attachments.field_id')
                ->where('attachments.inventory_id', $inventory->getKey())
                ->select(['attachments.id as row_id', 'attachments.ulid', 'items.ulid as item_ulid', 'inventory_fields.ulid as field_ulid', 'attachments.name', 'attachments.mime', 'attachments.size'])
                ->chunkById(self::CHUNK, function (Collection $chunk) use ($stream, &$files): void {
                    foreach ($chunk as $file) {
                        $this->put($stream, ($files === 0 ? '' : ",\n").json_encode([
                            'id' => $file->ulid,
                            'item_id' => $file->item_ulid,
                            'field_id' => $file->field_ulid,
                            'name' => $file->name,
                            'mime' => $file->mime,
                            'size' => (int) $file->size,
                        ], self::FLAGS));
                        $files++;
                    }
                }, 'attachments.id', 'row_id');

            $this->put($stream, "\n]\n}\n");
        } finally {
            fclose($stream);
        }

        return $items;
    }

    /**
     * The definition of a field.
     *
     * @return array<string, mixed>
     */
    private function field(InventoryField $field): array
    {
        return [
            'id' => $field->ulid,
            'name' => $field->name,
            'type' => $field->type,
            'position' => $field->position,
            'required' => $field->required,
            'options' => $this->object($field->options ?? []),
        ];
    }

    /**
     * An item with the values it holds.
     *
     * @return array<string, mixed>
     */
    private function item(Item $item): array
    {
        return [
            'id' => $item->ulid,
            'values' => $this->object($item->values),
            'created_at' => $item->created_at?->utc()->toIso8601String(),
            'updated_at' => $item->updated_at?->utc()->toIso8601String(),
        ];
    }

    /**
     * A keyed set as the document writes it: its keys sorted at every depth, and an object (`{}`) when it is empty, never a list.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>|stdClass
     */
    private function object(array $value): array|stdClass
    {
        $sorted = $this->sorted($value);

        return is_array($sorted) && $sorted !== [] ? $sorted : new stdClass;
    }

    /**
     * A value with the keys of every object in it sorted, so that the engine that stored it does not show in the text. A list keeps its
     * order.
     */
    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->sorted(...), $value);
    }

    /**
     * Writes a piece of the document.
     *
     * @param  resource  $stream
     *
     * @throws RuntimeException When the file cannot be written.
     * @throws SnapshotTooLargeException When the file would weigh more than the limit.
     */
    private function put($stream, string $text): void
    {
        $this->written += strlen($text);

        if ($this->written > config()->integer('inventories.max_snapshot_bytes')) {
            throw new SnapshotTooLargeException(config()->integer('inventories.max_snapshot_bytes'));
        }

        if (fwrite($stream, $text) === false) {
            throw new RuntimeException('The file of the version could not be written.');
        }
    }
}
