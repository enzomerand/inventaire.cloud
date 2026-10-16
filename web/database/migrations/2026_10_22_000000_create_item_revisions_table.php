<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the history of the items: one row per change, written in the transaction of the change.
 *
 * A revision belongs to its item and goes with it (the foreign key cascades). The author column forgets the person (not
 * the revision) when an account is deleted, the way the author columns of the items do. `changes` is a JSON object whose
 * shape depends on the `action` (see `App\Models\ItemRevision`); it is only ever read back whole, never searched, so it
 * is a plain `json` column on every engine. The history of an item is read newest first and pruned from the oldest, both
 * by the primary key: the index on `(item_id, id)` serves them, and the foreign key.
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
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            // PostgreSQL does not index a foreign key by itself; deleting an account nulls this column in every revision it wrote.
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->json('changes');
            $table->timestamp('created_at')->nullable();

            // Also serves the foreign key: the revisions of an item, newest first.
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
