<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Support\Env;

/**
 * The variables phpunit.xml sets, which are legitimately visible to the tests.
 *
 * @return list<string>
 */
function phpunitEnvironmentNames(): array
{
    $xml = simplexml_load_string((string) file_get_contents(base_path('phpunit.xml')));
    $names = [];

    foreach ($xml->php->env ?? [] as $env) {
        $names[] = (string) $env['name'];
    }

    return $names;
}

it('loads .env.testing, never the real .env', function (): void {
    expect(app()->environmentFile())->toBe('.env.testing')->and(is_file(base_path('.env.testing')))->toBeTrue();
});

it('keeps .env.testing free of values: everything the tests need is in phpunit.xml', function (): void {
    expect(Dotenv::parse((string) file_get_contents(base_path('.env.testing'))))->toBe([]);
});

it('does not see anything only the developer\'s real .env defines', function (): void {
    $real = is_file(base_path('.env')) ? Dotenv::parse((string) file_get_contents(base_path('.env'))) : [];
    $imposed = phpunitEnvironmentNames();

    // A value that reached the tests from the real .env (a mail host, an S3 bucket, an APP_URL...) would make a run depend on the machine.
    $leaked = array_filter(
        array_keys($real),
        static fn (string $key): bool => ! in_array($key, $imposed, true) && Env::get($key) !== null && Env::get($key) === $real[$key],
    );

    expect(array_values($leaked))->toBe([]);
});
