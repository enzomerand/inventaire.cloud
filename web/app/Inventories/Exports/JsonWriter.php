<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Models\InventoryField;
use RuntimeException;
use stdClass;

/**
 * Writes the inventory as a JSON document, the way another system can read it: the definitions of the fields and the items with
 * their values in the shape the API gives them.
 *
 * ```
 * {"format": "inventaire.cloud/export", "version": 1, "exported_at": "...Z",
 *  "inventory": {"id", "name", "description"},
 *  "fields": [{"id", "name", "type", "options", "required"}],
 *  "items": [{"id", "values": {<ULID of the field>: value}, "created_at", "updated_at"}]}
 * ```
 *
 * The document is written as a stream, an item at a time and one to a line: memory does not grow with the number of items. The
 * values are the ones of the API ({@see ExportTable::presented()}): numbers are decimal strings, moments are in UTC, a choice is the
 * id of the choice (the `options` of its field say what it stands for), a file or photo field holds the ULIDs of its attachments.
 * An empty `values` or `options` is an object (`{}`), never a list.
 */
final class JsonWriter implements ExportWriter
{
    /** The version of the shape of the document, raised when it changes in a way a reader would notice. */
    public const VERSION = 1;

    /** Readable and short: the text is not escaped, a character that is not valid UTF-8 is replaced instead of failing the export. */
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * Writes the document.
     *
     * @throws RuntimeException When the file cannot be written.
     */
    public function write(ExportContext $context, string $path): int
    {
        $stream = fopen($path, 'wb');

        if ($stream === false) {
            throw new RuntimeException('The file of the export could not be opened.');
        }

        try {
            $inventory = $context->inventory;
            $this->put($stream, '{"format":"inventaire.cloud/export","version":'.self::VERSION.',"exported_at":'.json_encode(now()->utc()->format('Y-m-d\TH:i:s\Z'), self::FLAGS)
                .",\n\"inventory\":".json_encode(['id' => $inventory->ulid, 'name' => $inventory->name, 'description' => $inventory->description], self::FLAGS)
                .",\n\"fields\":[\n");

            $this->put($stream, implode(",\n", $context->fields->map(fn (InventoryField $field): string => json_encode($this->field($field), self::FLAGS))->all()));
            $this->put($stream, "\n],\n\"items\":[\n");

            $rows = 0;

            foreach ($context->items() as $item) {
                $values = $context->table->presented($item);

                $this->put($stream, ($rows === 0 ? '' : ",\n").json_encode([
                    'id' => $item->ulid,
                    'values' => $values === [] ? new stdClass : $values,
                    'created_at' => $item->created_at?->toIso8601String(),
                    'updated_at' => $item->updated_at?->toIso8601String(),
                ], self::FLAGS));
                $rows++;
            }

            $this->put($stream, "\n]\n}\n");
        } finally {
            fclose($stream);
        }

        return $rows;
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
            'options' => $field->options === [] || $field->options === null ? new stdClass : $field->options,
            'required' => $field->required,
        ];
    }

    /**
     * Writes a piece of the document.
     *
     * @param  resource  $stream
     *
     * @throws RuntimeException When the file cannot be written.
     */
    private function put($stream, string $text): void
    {
        if (fwrite($stream, $text) === false) {
            throw new RuntimeException('The file of the export could not be written.');
        }
    }
}
