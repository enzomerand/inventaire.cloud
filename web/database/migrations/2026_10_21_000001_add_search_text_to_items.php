<?php

declare(strict_types=1);

use App\Inventories\ItemSearchText;
use App\Models\Inventory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the text an item is searched by, and writes it for the items that already exist.
 *
 * The text is in a form that ignores the case and the accents, which no database collation does the same way on
 * PostgreSQL, MySQL and MariaDB: it is built in PHP (`App\Inventories\ItemSearchText`) and the search is a plain `LIKE`
 * on it. The column is nullable: an item that holds nothing to search for has no text. The existing items are filled with
 * the very class the application uses when it writes an item, one inventory at a time and in chunks of items.
 *
 * It is not a table of its own: nothing is added to `ApplicationTables`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->longText('search_text')->nullable();
        });

        $searchText = app(ItemSearchText::class);

        Inventory::query()->chunkById(100, function ($inventories) use ($searchText): void {
            foreach ($inventories as $inventory) {
                $searchText->refresh($inventory, $inventory->fields()->get());
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
