<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('stops the suite before any migration when the database is not a test database', function (): void {
    // Run a real child suite whose environment names a database without the
    // "_test" suffix. The name does not exist on purpose: if the guard ever
    // regressed, the child would fail to connect instead of wiping dev data.
    $result = Process::path(base_path())
        ->env(['DB_DATABASE' => 'inventaire_guard_probe'])
        ->run([PHP_BINARY, 'artisan', 'test', '--filter', 'serves the home page']);

    expect($result->failed())->toBeTrue()
        ->and($result->output().$result->errorOutput())->toContain('Refusing to run tests');
});
