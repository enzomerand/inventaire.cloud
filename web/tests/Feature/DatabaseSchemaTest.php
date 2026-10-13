<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * Runs the real migrations on whichever engine DB_CONNECTION points to
 * (PostgreSQL by default, MySQL through `composer test:mysql`). It is the canary
 * proving the schema stays engine-agnostic.
 */
uses(RefreshDatabase::class);

it('creates the core tables', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue("Table \"{$table}\" is missing.");
})->with([
    'migrations',
    'users',
    'password_reset_tokens',
    'sessions',
    'cache',
    'cache_locks',
    'jobs',
    'job_batches',
    'failed_jobs',
]);

it('reads back a row written through Eloquent', function (): void {
    $user = User::factory()->create(['email' => 'reader@example.com']);

    expect(User::query()->where('email', 'reader@example.com')->value('id'))->toBe($user->id);
});

it('enforces the unique email constraint', function (): void {
    User::factory()->create(['email' => 'unique@example.com']);

    User::factory()->create(['email' => 'unique@example.com']);
})->throws(UniqueConstraintViolationException::class);
