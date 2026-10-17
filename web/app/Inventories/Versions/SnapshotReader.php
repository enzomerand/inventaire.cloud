<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

use App\Models\InventoryField;
use App\Models\Item;
use Illuminate\Support\Collection;
use JsonException;
use RuntimeException;

/**
 * Reads the snapshot of an official version back into the models the export code lays out ({@see VersionContent}), after checking
 * that it is the document the version froze: the bytes must have the size and the SHA-256 recorded with the version, so that a PDF is
 * never written from a file that was altered, truncated or swapped on the disk.
 */
final class SnapshotReader
{
    /**
     * The content of a snapshot.
     *
     * @param  string  $contents  The bytes of `snapshot.json`.
     * @param  int  $size  The size recorded with the version.
     * @param  string  $sha256  The SHA-256 recorded with the version.
     *
     * @throws RuntimeException When the bytes are not the ones that were frozen, or are not a snapshot this version of the application reads.
     */
    public function read(string $contents, int $size, string $sha256): VersionContent
    {
        if (strlen($contents) !== $size || ! hash_equals($sha256, hash('sha256', $contents))) {
            throw new RuntimeException('The snapshot is not the document that was frozen: its size or its SHA-256 differs.');
        }

        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The snapshot is not valid JSON.', 0, $exception);
        }

        if (! is_array($document) || ($document['format'] ?? null) !== SnapshotWriter::FORMAT || ($document['format_version'] ?? null) !== SnapshotWriter::FORMAT_VERSION) {
            throw new RuntimeException('The snapshot has a format this version of the application does not read.');
        }

        $workspace = $this->section($document, 'workspace');
        $inventory = $this->section($document, 'inventory');
        $fields = new Collection;

        foreach ($this->rows($document, 'fields') as $row) {
            $field = new InventoryField;
            $field->forceFill([
                'ulid' => $this->text($row, 'id'),
                'name' => $this->text($row, 'name'),
                'type' => $this->text($row, 'type'),
                'position' => (int) ($row['position'] ?? 0),
                'required' => (bool) ($row['required'] ?? false),
                'options' => is_array($row['options'] ?? null) ? $row['options'] : [],
            ]);
            $fields->push($field);
        }

        $items = [];

        foreach ($this->rows($document, 'items') as $row) {
            $item = new Item;
            $item->forceFill([
                'ulid' => $this->text($row, 'id'),
                'values' => is_array($row['values'] ?? null) ? $row['values'] : [],
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ]);
            $items[] = $item;
        }

        $description = $inventory['description'] ?? null;

        return new VersionContent($this->text($workspace, 'name'), $this->text($inventory, 'name'), is_string($description) ? $description : null, $fields, $items);
    }

    /**
     * A section of the document that holds a set of named values.
     *
     * @param  array<array-key, mixed>  $document
     * @return array<array-key, mixed>
     *
     * @throws RuntimeException When it is not there.
     */
    private function section(array $document, string $key): array
    {
        $section = $document[$key] ?? null;

        return is_array($section) ? $section : throw new RuntimeException("The snapshot has no [{$key}].");
    }

    /**
     * The rows of a list of the document.
     *
     * @param  array<array-key, mixed>  $document
     * @return list<array<array-key, mixed>>
     *
     * @throws RuntimeException When the list is not there, or holds something else than sets of values.
     */
    private function rows(array $document, string $key): array
    {
        $rows = $document[$key] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException("The snapshot has no list [{$key}].");
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException("The list [{$key}] of the snapshot holds something that is not a set of values.");
            }
        }

        /** @var list<array<array-key, mixed>> $rows */
        return $rows;
    }

    /**
     * A text of a section.
     *
     * @param  array<array-key, mixed>  $section
     *
     * @throws RuntimeException When it is not a text.
     */
    private function text(array $section, string $key): string
    {
        $value = $section[$key] ?? null;

        return is_string($value) ? $value : throw new RuntimeException("The snapshot has no text [{$key}].");
    }
}
