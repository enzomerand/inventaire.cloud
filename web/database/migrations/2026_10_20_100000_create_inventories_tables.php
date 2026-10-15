<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the inventories of a workspace, the typed fields that describe them, and the items they hold.
 *
 * An inventory belongs to a workspace, a field to an inventory and an item to an inventory: deleting any of them
 * deletes what hangs from it, in the database itself, so that no code path can leave an orphan behind. Each table has
 * a numeric key for the database and a ULID for the outside world (URLs, API).
 *
 * The values of an item are one JSON object, keyed by the ULID of the field they belong to: one row per item keeps
 * history and exports simple, and the field definitions are what give the values their meaning. A field's `type` is a
 * word of the field type registry (`App\Inventories\Fields\FieldTypes`) in a plain string, so a type can be added
 * without a migration; `options` holds what that type is configured with. `position` orders the fields of an
 * inventory. The author columns forget the person (not the item) when an account is deleted.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // PostgreSQL does not index a foreign key by itself; deleting a workspace and listing its inventories need it.
            $table->foreignId('workspace_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_fields', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 32);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('position');
            $table->timestamps();

            // Also serves the foreign key: the fields of an inventory, in order.
            $table->index(['inventory_id', 'position']);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            // No default: a JSON column cannot have one on every engine. The application always writes an object.
            $table->json('values');
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Also serves the foreign key: the items of an inventory, newest first (the key grows with time).
            $table->index(['inventory_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
        Schema::dropIfExists('inventory_fields');
        Schema::dropIfExists('inventories');
    }
};
