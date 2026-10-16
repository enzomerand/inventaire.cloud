<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the files attached to items.
 *
 * An attachment belongs to an item, and the ones that fill a file or photo field also to that field (`field_id`); the
 * application deletes them when the item or the field goes (`Items::delete()`, `InventoryFields::delete()`: it has to
 * purge the files anyway). The database is not told so by foreign keys to `items` and `inventory_fields`: the rows hang
 * from the inventory (`inventory_id`, which cascades), because MySQL (9.7 at the time of writing) does not carry a cascade
 * through three levels (a workspace, its inventories, their items, the files of the items) beyond the first inventory: see
 * the migration that creates `item_revisions`. The row is the source of truth for the file: its place on
 * the disk (`disk`, `path`, `thumbnail_path`), what it is (`mime`, as detected from the content when it was uploaded; `size`;
 * the dimensions of an image) and the name it had, kept for display and for the download only, never in a path. The
 * person who uploaded it is forgotten (not the file) when their account is deleted.
 *
 * The database cannot delete the files a cascade removes: the application purges them (see `App\Jobs\PurgeStoredFiles`).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // PostgreSQL does not index a foreign key by itself; deleting an inventory needs it.
            $table->foreignId('inventory_id')->index()->constrained()->cascadeOnDelete();
            // No foreign keys on the next two: see above.
            $table->unsignedBigInteger('item_id')->index();
            $table->unsignedBigInteger('field_id')->nullable()->index();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->string('name', 255);
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->foreignId('uploaded_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
