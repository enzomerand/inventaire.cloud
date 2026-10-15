<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the table of the instance settings: the choices an administrator makes for the whole instance (see
 * App\Settings\InstanceSettings).
 *
 * One row per setting: a short key, which is the primary key, and its value as JSON, so that a setting can be a flag,
 * a number or a list without a migration. A setting that has no row holds its default.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
