<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the imports: the spreadsheets a person brings to add items to an inventory.
 *
 * A row is the file first (`format`, `file_name`, `size`, where it is stored in `disk` and `path`), what was read from it
 * (`columns`: the header, a few samples and the type guessed for each column) and what the person chose (`options`: the header
 * row, the separator and encoding of a CSV, the decimal sign, the order of the days and months, what to do with a row that is
 * refused; `mapping`: where each column goes). Then the result: `status` goes from `uploaded` (the file was analysed, the person is
 * choosing) to `pending` (started, waiting for the queue), `running`, `completed` or `failed` (`error` holds the code of the failure).
 * A finished import keeps its report (`rows_total`, `rows_imported`, `rows_failed`, `fields_created` and the first `errors`) until
 * `expires_at`, but not its file: `path` is null once the source is deleted, which is as soon as the job ends.
 *
 * The rows hang from the inventory and from the person (both cascade): an import is at the second level below a workspace, so the
 * chain stays within the three levels MySQL (9.7 at the time of writing) can cascade. The database cannot delete the files a cascade
 * removes: they live under the folder of the inventory and the application purges them (see `App\Jobs\PurgeStoredFiles`).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // PostgreSQL does not index a foreign key by itself; deleting an inventory needs it. The one of the person
            // is the first column of the index below.
            $table->foreignId('inventory_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('format', 8);
            $table->string('status', 16);
            $table->string('error', 64)->nullable();
            $table->string('disk', 64);
            $table->string('path')->nullable();
            $table->string('file_name', 255);
            $table->unsignedBigInteger('size');
            $table->string('locale', 12);
            $table->json('options');
            $table->json('columns');
            $table->json('mapping')->nullable();
            $table->unsignedInteger('rows_total')->nullable();
            $table->unsignedInteger('rows_imported')->nullable();
            $table->unsignedInteger('rows_failed')->nullable();
            $table->unsignedInteger('fields_created')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // The imports of a person, newest first; the ones that are stuck; the ones that expired.
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
        Schema::dropIfExists('imports');
    }
};
