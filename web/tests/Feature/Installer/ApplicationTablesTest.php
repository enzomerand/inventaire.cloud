<?php

declare(strict_types=1);

use App\Installer\ApplicationTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('covers every table the migrations create', function (): void {
    $created = Schema::getTableListing(schemaQualified: false);

    expect(array_values(array_filter($created, static fn (string $table): bool => ! ApplicationTables::owns($table))))
        ->toBe([], 'Add the new table(s) to App\\Installer\\ApplicationTables::NAMES.');
});

it('does not list tables that no migration creates', function (): void {
    $created = Schema::getTableListing(schemaQualified: false);

    expect(array_values(array_diff(ApplicationTables::NAMES, $created)))->toBe([]);
});
