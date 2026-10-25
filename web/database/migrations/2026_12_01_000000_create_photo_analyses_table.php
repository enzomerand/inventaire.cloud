<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the photo analyses: the photos of one object a person sends so that Claude proposes the values of its fields.
 *
 * A row is created `pending` when the photos are received (`photo_count` of them, re-encoded and stored in a folder of the inventory on
 * `disk`), goes `running` when a worker takes it, and ends `completed` (`proposals`: what Claude read, already converted and checked
 * against the fields, with `model` and the tokens it was billed) or `failed` (`error` holds the code of the failure). The photos are
 * deleted as soon as the job ends, whatever the outcome; the proposals are kept until `expires_at`, so that the person can read them
 * back, and then the row goes too.
 *
 * The rows hang from the inventory and from the person (both cascade): an analysis is at the second level below a workspace, so the
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
        Schema::create('photo_analyses', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // PostgreSQL does not index a foreign key by itself; deleting an inventory needs it. The one of the person
            // is the first column of the index below.
            $table->foreignId('inventory_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('error', 64)->nullable();
            $table->string('disk', 64);
            $table->string('locale', 12);
            $table->unsignedTinyInteger('photo_count');
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->json('proposals')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // The analyses of a person, newest first; the ones that are stuck; the ones that expired.
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
        Schema::dropIfExists('photo_analyses');
    }
};
