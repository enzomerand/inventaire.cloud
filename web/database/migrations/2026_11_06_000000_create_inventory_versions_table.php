<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the official versions of an inventory: its state frozen at a date, numbered inventory by inventory, with the PDF written
 * from that state and, when signatures were asked for, the follow-up of their request to Documenso.
 *
 * A row is the freeze first (`number`, `label`, the author's name and the date, the counts, and the `snapshot_*` columns that say
 * where the canonical JSON of the data is, how big it is and what its SHA-256 is), then the PDF (`status` goes from `pending` to
 * `running` to `completed` or `failed`, `error` holds the code of the failure, and `pdf_*` describe the file), then the signature
 * (`signature_status`, the `signers` and their state, the envelope at Documenso, and the signed PDF). `counted_bytes` is what the
 * version currently adds to `workspaces.storage_used`: the only figure that is ever taken back from it.
 *
 * The rows hang from the inventory (cascade), which is at the second level below a workspace: the chain stays within the three
 * levels MySQL (9.7 at the time of writing) can cascade. The database cannot delete the files a cascade removes: they live under
 * the folder of the inventory and the application purges them (see `App\Jobs\PurgeStoredFiles`). The author is only remembered
 * by id: deleting the account forgets the person, not the version (the name was frozen with it).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_versions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // The unique index below starts with the inventory: it serves the foreign key (PostgreSQL does not index one by itself).
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            // PostgreSQL does not index a foreign key by itself; deleting an account nulls this column in every version it froze.
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('number');

            // What was frozen.
            $table->string('label', 120)->nullable();
            $table->string('author_name', 255);
            $table->string('locale', 8);
            $table->timestamp('frozen_at');
            $table->unsignedInteger('items_count');
            $table->unsignedInteger('fields_count');

            // The PDF: `error` is a code of App\Inventories\Versions\VersionError.
            $table->string('status', 16);
            $table->string('error', 32)->nullable();

            // The files, under the folder of the version in the inventory's.
            $table->string('disk', 64);
            $table->string('snapshot_path');
            $table->unsignedBigInteger('snapshot_size');
            $table->char('snapshot_sha256', 64);
            $table->string('pdf_path')->nullable();
            $table->unsignedBigInteger('pdf_size')->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->unsignedInteger('pdf_pages')->nullable();
            // The bytes of this version that `workspaces.storage_used` holds now (0 until the PDF is written).
            $table->unsignedBigInteger('counted_bytes')->default(0);

            // The signature: `signature_status` and `signature_error` are codes of App\Inventories\Versions\SignatureStatus and SignatureError.
            $table->string('signature_status', 16)->nullable();
            $table->string('signature_error', 32)->nullable();
            // [{name, email, status, signed_at, rejection_reason, recipient_id, field: {page, x, y, width, height}}]: only ever read back whole.
            $table->json('signers')->nullable();
            $table->string('documenso_envelope_id', 64)->nullable()->index();
            $table->timestamp('signature_requested_at')->nullable();
            $table->timestamp('signature_completed_at')->nullable();
            $table->timestamp('signature_synced_at')->nullable();
            $table->string('signed_pdf_path')->nullable();
            $table->unsignedBigInteger('signed_pdf_size')->nullable();
            $table->char('signed_pdf_sha256', 64)->nullable();
            $table->timestamps();

            // The numbers of an inventory; the versions that are stuck; the signatures to synchronise.
            $table->unique(['inventory_id', 'number']);
            $table->index(['status', 'created_at']);
            $table->index(['signature_status', 'signature_synced_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_versions');
    }
};
