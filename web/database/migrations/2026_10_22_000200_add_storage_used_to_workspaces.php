<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the number of bytes the files of a workspace use on the workspace itself.
 *
 * Summing the sizes of the attachments is what the quota would otherwise do at every upload, and it grows with the
 * workspace. The counter is read in O(1) and is only ever written with the workspace locked (see
 * `App\Inventories\Attachments\WorkspaceStorage`), so it cannot drift under concurrent uploads. No file exists before this
 * column does, so it starts at zero everywhere; `inventaire:upgrade` sums the sizes again and corrects any difference.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('workspaces', 'storage_used')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->unsignedBigInteger('storage_used')->default(0);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('storage_used');
        });
    }
};
