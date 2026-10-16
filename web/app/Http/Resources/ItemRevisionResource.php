<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Inventories\Fields\FieldTypes;
use App\Models\InventoryField;
use App\Models\ItemRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * How the API describes one line of the history of an item: its number, what happened, who did it and when, and the
 * changes, as a list.
 *
 * - `user` is the person's `id` and `name`, or null when their account was deleted since;
 * - for `created` and `updated`, each change is `{field, before, after}`: the values are shown the way the item shows them
 *   now (through the current definition of the field: a choice deleted from a select since is gone), and `field`
 *   (`{id, name, type}`) is null when the field was deleted since, in which case the values are the stored ones;
 * - for `attachment_added` and `attachment_removed`, the one change is `{attachment: {id, name}, field}`, where `field` is
 *   the file or photo field the file belonged to (null for a plain attachment, or when the field was deleted since).
 *
 * @mixin ItemRevision
 */
final class ItemRevisionResource extends JsonResource
{
    /**
     * Describes a revision against the fields of the inventory the item is in.
     *
     * @param  ItemRevision  $resource  With its `user` loaded.
     * @param  Collection<string, InventoryField>  $fields  The fields of the item's inventory, keyed by ULID, in order.
     */
    public function __construct($resource, private readonly Collection $fields)
    {
        parent::__construct($resource);
    }

    /**
     * The revision, as the API gives it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource->user;

        return [
            'id' => $this->resource->getKey(),
            'action' => $this->action,
            'user' => $user === null ? null : ['id' => $user->getKey(), 'name' => $user->name],
            'created_at' => $this->created_at?->toIso8601String(),
            'changes' => in_array($this->action, [ItemRevision::ATTACHMENT_ADDED, ItemRevision::ATTACHMENT_REMOVED], true)
                ? $this->attachmentChanges()
                : $this->valueChanges(),
        ];
    }

    /**
     * The changes of values, in the order of the fields as they are now, then the ones of fields that are gone.
     *
     * @return list<array{field: array{id: string, name: string, type: string}|null, before: mixed, after: mixed}>
     */
    private function valueChanges(): array
    {
        $stored = $this->resource->changes;
        $changes = [];

        foreach ($this->fields as $ulid => $field) {
            if (isset($stored[$ulid]) && is_array($stored[$ulid])) {
                $changes[] = $this->valueChange($field, $stored[$ulid]);
                unset($stored[$ulid]);
            }
        }

        foreach ($stored as $change) {
            if (is_array($change)) {
                $changes[] = $this->valueChange(null, $change);
            }
        }

        return $changes;
    }

    /**
     * One change of value.
     *
     * @param  array<array-key, mixed>  $change  `before` and `after`, as stored.
     * @return array{field: array{id: string, name: string, type: string}|null, before: mixed, after: mixed}
     */
    private function valueChange(?InventoryField $field, array $change): array
    {
        return [
            'field' => $this->describeField($field),
            'before' => $this->show($field, $change['before'] ?? null),
            'after' => $this->show($field, $change['after'] ?? null),
        ];
    }

    /**
     * The change of a file attached or removed.
     *
     * @return list<array{attachment: array{id: mixed, name: mixed}, field: array{id: string, name: string, type: string}|null}>
     */
    private function attachmentChanges(): array
    {
        $stored = $this->resource->changes;
        $field = is_string($stored['field'] ?? null) ? ($this->fields[$stored['field']] ?? null) : null;

        return [[
            'attachment' => ['id' => $stored['attachment'] ?? null, 'name' => $stored['name'] ?? null],
            'field' => $this->describeField($field),
        ]];
    }

    /**
     * A stored value the way the item shows it now: through the type of its field, null when nothing is left to show.
     * A field that is gone (or of a type this version does not know) has no definition to show it with: the value is
     * given as it was stored.
     */
    private function show(?InventoryField $field, mixed $stored): mixed
    {
        $type = $field === null ? null : app(FieldTypes::class)->of($field);

        if ($stored === null || $field === null || $type === null) {
            return $stored;
        }

        $shown = $type->present($stored, $field);

        return $type->isEmpty($shown) ? null : $shown;
    }

    /**
     * The field as the history names it, or null when it is gone.
     *
     * @return array{id: string, name: string, type: string}|null
     */
    private function describeField(?InventoryField $field): ?array
    {
        return $field === null ? null : ['id' => $field->ulid, 'name' => $field->name, 'type' => $field->type];
    }
}
