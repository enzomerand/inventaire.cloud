<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the history of the items: one row per change, written in the transaction of the change.
 *
 * A revision belongs to an item and goes with it, but the database is not told so by a foreign key to `items`: it hangs
 * from the inventory of the item (`inventory_id`, which cascades), and `Items::delete()` deletes the revisions of the item it
 * deletes. The reason is MySQL (9.7 at the time of writing): when a deletion cascades through three levels or more (a
 * workspace, its inventories, their items, the revisions of the items), the cascade of the second inventory onwards stops
 * after the inventory itself, and its items stay behind with no inventory. Foreign keys that act (cascade, set null) on a
 * table that is itself reached by a cascade from two levels above are therefore kept out of the schema: everything that
 * hangs from an inventory hangs from the inventory itself.
 *
 * The author column forgets the person (not the revision) when an account is deleted, the way the author columns of the
 * items do. `changes` is a JSON object whose shape depends on the `action` (see `App\Models\ItemRevision`); it is only
 * ever read back whole, never searched, so it is a plain `json` column on every engine. The history of an item is read
 * newest first and pruned from the oldest, both by the primary key: the index on `(item_id, id)` serves them.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('item_revisions', function (Blueprint $table) {
            $table->id();
            // PostgreSQL does not index a foreign key by itself; deleting an inventory needs it.
            $table->foreignId('inventory_id')->index()->constrained()->cascadeOnDelete();
            // No foreign key: see above. The index below serves the history of an item.
            $table->unsignedBigInteger('item_id');
            // PostgreSQL does not index a foreign key by itself; deleting an account nulls this column in every revision it wrote.
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->json('changes');
            $table->timestamp('created_at')->nullable();

            // The revisions of an item, newest first.
            $table->index(['item_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_revisions');
    }
};
