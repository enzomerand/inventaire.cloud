<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * Runs the real migrations on whichever engine DB_CONNECTION points to.
 * It is the canary proving the schema stays engine-agnostic.
 */
uses(RefreshDatabase::class);

it('creates the core tables', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue("Table \"{$table}\" is missing.");
})->with([
    'migrations',
    'users',
    'sessions',
    'cache',
    'cache_locks',
    'jobs',
    'job_batches',
    'failed_jobs',
    'personal_access_tokens',
    'login_codes',
    'workspaces',
    'workspace_user',
    'account_deletion_codes',
]);

it('no longer has the password reset tokens table', function (): void {
    expect(Schema::hasTable('password_reset_tokens'))->toBeFalse();
});

it('enforces the unique email constraint', function (): void {
    User::factory()->create(['email' => 'unique@example.com']);

    User::factory()->create(['email' => 'unique@example.com']);
})->throws(UniqueConstraintViolationException::class);
