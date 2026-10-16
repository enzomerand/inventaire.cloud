<?php

declare(strict_types=1);

namespace App\Inventories;

use App\Models\Item;
use App\Models\ItemRevision;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * Writes the history of the items: one {@see ItemRevision} per change, and no more than
 * `inventories.max_revisions_per_item` of them per item.
 *
 * Every method is called by the code that makes the change, inside its transaction and with the item locked (see
 * {@see Items}): the revision exists if and only if the change does, and the pruning of the oldest ones cannot overlap
 * another write to the same item. Revisions are written with the query builder's bulk statements where it matters (the
 * pruning), and never touch the item.
 */
final class ItemRevisions
{
    /**
     * Gets the database.
     */
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Records that an item was added, with every value it was given.
     *
     * @param  array<string, mixed>  $values  The stored values of the new item, keyed by the ULID of the field.
     */
    public function created(Item $item, ?User $author, array $values): void
    {
        $changes = [];

        foreach ($values as $ulid => $value) {
            $changes[$ulid] = ['before' => null, 'after' => $value];
        }

        $this->write($item, $author, ItemRevision::CREATED, $changes);
    }

    /**
     * Records the values of an item that changed, when some did: nothing is written when the two sets are the same.
     *
     * @param  array<string, mixed>  $before  The stored values the item held.
     * @param  array<string, mixed>  $after  The stored values it holds now.
     * @return bool Whether a revision was written.
     */
    public function updated(Item $item, ?User $editor, array $before, array $after): bool
    {
        $changes = [];

        foreach ($after as $ulid => $value) {
            if (($before[$ulid] ?? null) !== $value) {
                $changes[$ulid] = ['before' => $before[$ulid] ?? null, 'after' => $value];
            }
        }

        foreach ($before as $ulid => $value) {
            if (! array_key_exists($ulid, $after)) {
                $changes[$ulid] = ['before' => $value, 'after' => null];
            }
        }

        if ($changes === []) {
            return false;
        }

        $this->write($item, $editor, ItemRevision::UPDATED, $changes);

        return true;
    }

    /**
     * Records that a file was attached to an item, or removed from it.
     *
     * @param  'attachment_added'|'attachment_removed'  $action
     * @param  string  $attachment  The ULID of the attachment.
     * @param  string  $name  The name of the file as it was kept: the attachment may be gone when the history is read.
     * @param  string|null  $field  The ULID of the file or photo field it belongs to, or null for a plain attachment.
     */
    public function attachment(Item $item, ?User $author, string $action, string $attachment, string $name, ?string $field): void
    {
        $this->write($item, $author, $action, ['attachment' => $attachment, 'name' => $name, 'field' => $field]);
    }

    /**
     * Deletes the whole history of an item that is being deleted (see the migration that creates the table: the database
     * does not cascade from the item).
     */
    public function forget(Item $item): void
    {
        $this->db->table('item_revisions')->where('item_id', $item->getKey())->delete();
    }

    /**
     * Writes one revision, then prunes the history of the item.
     *
     * @param  array<string, mixed>  $changes
     */
    private function write(Item $item, ?User $author, string $action, array $changes): void
    {
        $revision = new ItemRevision;
        $revision->inventory_id = $item->inventory_id;
        $revision->item_id = $item->getKey();
        $revision->user_id = $author?->getKey();
        $revision->action = $action;
        $revision->changes = $changes;
        $revision->save();

        $this->prune((int) $item->getKey());
    }

    /**
     * Deletes the oldest revisions of an item beyond the most it keeps.
     *
     * The id of the first revision to go (the one just past the newest ones to keep) is read with a plain `OFFSET`, and
     * it and every older one are deleted by id: a `DELETE` with a `LIMIT` in a sub-query is not accepted by MySQL and
     * MariaDB. The caller holds the item locked, so nobody adds a revision between the read and the delete.
     */
    private function prune(int $itemId): void
    {
        $keep = max(1, config()->integer('inventories.max_revisions_per_item'));

        $first = $this->db->table('item_revisions')->where('item_id', $itemId)->orderByDesc('id')->offset($keep)->limit(1)->value('id');

        if ($first !== null) {
            $this->db->table('item_revisions')->where('item_id', $itemId)->where('id', '<=', $first)->delete();
        }
    }
}
