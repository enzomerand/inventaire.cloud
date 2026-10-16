<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the files attached to items.
 *
 * An attachment belongs to an item and goes with it (the foreign key cascades). The ones that fill a file or photo
 * field also belong to that field (`field_id`) and go with it. The row is the source of truth for the file: its place on
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
            // PostgreSQL does not index a foreign key by itself; deleting an item or a field needs these.
            $table->foreignId('item_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('field_id')->nullable()->index()->constrained('inventory_fields')->cascadeOnDelete();
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
