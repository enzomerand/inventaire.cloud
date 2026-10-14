<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Migrates the test database like {@see RefreshDatabase}, but does not wrap the test in a transaction.
 *
 * What the test writes is committed, so that other processes and connections see it: the tests that start PHP
 * processes against the database need that. The price is that the test must remove what it wrote, by itself.
 */
trait CommitsDatabaseChanges
{
    use RefreshDatabase;

    /**
     * No connection is wrapped in a transaction.
     *
     * @var list<string|null>
     */
    protected array $connectionsToTransact = [];
}
