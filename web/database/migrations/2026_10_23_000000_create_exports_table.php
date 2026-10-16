<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the exports: the files a person asks for to take the items of an inventory out of the application.
 *
 * A row is the request first (`format`, `scope`, the view it was made on in `options`, the language it is written in),
 * then the result: `status` goes from `pending` to `running` to `completed` or `failed` (`error` holds the code of the
 * failure), and a completed export knows where its file is (`disk`, `path`, `file_name`, `mime`, `size`) and until when
 * (`expires_at`). The file is the person's own: only they can download it, and it is deleted with the row.
 *
 * The rows hang from the inventory and from the person (both cascade): an export is at the second level below a
 * workspace, so the chain stays within the three levels MySQL (9.7 at the time of writing) can cascade. The database
 * cannot delete the files a cascade removes: they live under the folder of the inventory and the application purges them
 * (see `App\Jobs\PurgeStoredFiles`).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // PostgreSQL does not index a foreign key by itself; deleting an inventory needs it. The one of the person
            // is the first column of the index below.
            $table->foreignId('inventory_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('format', 8);
            $table->string('scope', 8);
            $table->json('options')->nullable();
            $table->string('locale', 12);
            $table->string('status', 16);
            $table->string('error', 64)->nullable();
            $table->string('disk', 64);
            $table->string('path')->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('mime', 127)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('items_count')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // The exports of a person, newest first; the ones that are stuck; the ones that expired.
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
