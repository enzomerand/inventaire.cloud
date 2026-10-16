<?php

declare(strict_types=1);

namespace App\Inventories;

use App\Database\DeadlockRetries;
use App\Inventories\Attachments\AttachmentCleanup;
use App\Inventories\Exceptions\FieldLimitReachedException;
use App\Inventories\Exceptions\InventoryLimitReachedException;
use App\Inventories\Templates\Template;
use App\Models\Inventory;
use App\Models\Workspace;
use App\Rules\SingleLineText;
use App\Workspaces\Locks;
use Collator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Listing, creating, changing and deleting the inventories of a workspace.
 *
 * Who may do it is decided by `App\Policies\InventoryPolicy` before this is called. What is decided here is what has
 * to be judged under a lock: the number of inventories a workspace holds is counted with the row of the workspace
 * locked (`inventories.max_per_workspace`), so requests made at the same moment cannot cross it together, and a
 * change or a deletion works on the inventory as it is now, locked, not on the copy the request read. Every write
 * is one transaction, retried when the database picks it as the loser of a deadlock, and takes its locks in the order
 * of {@see Locks}.
 */
final class Inventories
{
    /**
     * Gets what the rules of inventories need: the database and the locks.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly AttachmentCleanup $files,
        private readonly InventoryFields $fields,
    ) {}

    /**
     * The inventories of the workspace, in the order a person expects to read them: by name in the language of the
     * request, the case not counted, the accents counted (as the second difference: "Elan" comes before "Élan", and both
     * before "Elbe") and numbers as numbers ("Box 2" before "Box 10"). Each comes with the number of its fields and of
     * its items.
     *
     * @return Collection<int, Inventory>
     */
    public function list(Workspace $workspace): Collection
    {
        $collator = new Collator(app()->getLocale());
        $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        $collator->setStrength(Collator::SECONDARY);

        return $workspace->inventories()
            ->withCount('fields')
            ->get()
            ->sort(fn (Inventory $first, Inventory $second): int => $collator->compare($first->name, $second->name) ?: $first->id <=> $second->id)
            ->values();
    }

    /**
     * Creates an inventory in the workspace.
     *
     * @param  string  $name  Already validated and trimmed.
     * @param  string|null  $description  Already validated; null for none.
     *
     * @throws InventoryLimitReachedException When the workspace already holds as many inventories as allowed.
     * @throws ModelNotFoundException When the workspace was deleted in the meantime.
     */
    public function create(Workspace $workspace, string $name, ?string $description): Inventory
    {
        return DeadlockRetries::run(fn (): Inventory => $this->db->transaction(fn (): Inventory => $this->insert($workspace, $name, $description)));
    }

    /**
     * Creates an inventory in the workspace with the fields of a template: the inventory and every field, or nothing.
     *
     * It is one transaction, retried as a whole when the database picks it as the loser of a deadlock: the workspace is
     * locked, the quota of inventories is counted, the inventory is inserted, then its fields one by one with the checks
     * of a field made by hand (the quota of fields, the name, the options), whose insertion needs no lock of its own since
     * nobody can see the inventory before the commit. A field that fails (the quota of fields included) rolls the inventory
     * back with it.
     *
     * @param  string  $name  Already validated and trimmed.
     * @param  string|null  $description  Already validated; null for none.
     * @param  string  $locale  The language the field names and the choices are written in.
     *
     * @throws InventoryLimitReachedException When the workspace already holds as many inventories as allowed.
     * @throws FieldLimitReachedException When the template has more fields than an inventory may hold.
     * @throws ModelNotFoundException When the workspace was deleted in the meantime.
     */
    public function createFromTemplate(Workspace $workspace, Template $template, string $name, ?string $description, string $locale): Inventory
    {
        $definitions = $template->fields($locale);

        return DeadlockRetries::run(fn (): Inventory => $this->db->transaction(function () use ($workspace, $name, $description, $definitions): Inventory {
            $inventory = $this->insert($workspace, $name, $description);
            $fields = new SupportCollection;

            foreach ($definitions as $definition) {
                $this->fields->insert($inventory, $fields, $definition->type, SingleLineText::normalize($definition->name), $definition->creationOptions(), $definition->required);
            }

            return $inventory;
        }));
    }

    /**
     * Changes the name and the description of an inventory: only the attributes that are given.
     *
     * @param  array{name?: string, description?: string|null}  $changes  Already validated and trimmed.
     *
     * @throws ModelNotFoundException When the inventory was deleted in the meantime.
     */
    public function update(Inventory $inventory, array $changes): Inventory
    {
        return DeadlockRetries::run(fn (): Inventory => $this->db->transaction(function () use ($inventory, $changes): Inventory {
            $locked = $this->locks->inventory($inventory->getKey());

            if (isset($changes['name'])) {
                $changes['name'] = SingleLineText::normalize($changes['name']);
            }

            $locked->fill($changes)->save();

            return $locked;
        }));
    }

    /**
     * Deletes an inventory, and its fields, items, history and files with it (the foreign keys cascade).
     *
     * The workspace is locked first (see {@see Locks}): the files of the inventory are counted out of its storage in this
     * transaction. The inventory goes through the model's own `delete()`, so that its events fire: the `deleting` event
     * works out the inventory's folder on the disk and its `deleted` event queues the deletion, to be run once the transaction
     * is committed.
     *
     * @throws ModelNotFoundException When the workspace or the inventory was deleted in the meantime.
     */
    public function delete(Inventory $inventory): void
    {
        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($inventory): void {
            $workspace = $this->locks->workspace($inventory->workspace_id);
            $locked = $this->locks->inventory($inventory->getKey());

            $this->files->releaseInventory($workspace, $locked);
            $locked->delete();
        }));
    }

    /**
     * Inserts an inventory under the lock of its workspace, inside the caller's transaction.
     *
     * @throws InventoryLimitReachedException
     * @throws ModelNotFoundException
     */
    private function insert(Workspace $workspace, string $name, ?string $description): Inventory
    {
        if ($this->locks->workspaces([$workspace->getKey()])->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(Workspace::class, [$workspace->getKey()]);
        }

        $limit = config()->integer('inventories.max_per_workspace');

        if (Inventory::query()->where('workspace_id', $workspace->getKey())->count() >= $limit) {
            throw new InventoryLimitReachedException($limit);
        }

        $inventory = new Inventory(['name' => SingleLineText::normalize($name), 'description' => $description]);
        $inventory->workspace_id = $workspace->getKey();
        $inventory->save();

        return $inventory;
    }
}
