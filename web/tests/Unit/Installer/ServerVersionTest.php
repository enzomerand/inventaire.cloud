<?php

declare(strict_types=1);

use App\Installer\Probes\ServerVersion;

it('reads the engine and version a server reports', function (string $driver, string $reported, string $flavor, string $version): void {
    $server = ServerVersion::parse($driver, $reported);

    expect($server->flavor)->toBe($flavor)->and($server->version)->toBe($version);
})->with([
    'postgres' => ['pgsql', '17.2', 'pgsql', '17.2'],
    'postgres with distribution suffix' => ['pgsql', '16.2 (Debian 16.2-1.pgdg120+2)', 'pgsql', '16.2'],
    'postgres major only' => ['pgsql', '18', 'pgsql', '18'],
    'mysql' => ['mysql', '8.0.35', 'mysql', '8.0.35'],
    'mysql innovation' => ['mysql', '9.7.0', 'mysql', '9.7.0'],
    'mysql with vendor suffix' => ['mysql', '8.4.3-commercial', 'mysql', '8.4.3'],
    'mariadb' => ['mysql', '10.11.6-MariaDB-1:10.11.6+maria~ubu2204', 'mariadb', '10.11.6'],
    'mariadb with the fake protocol prefix' => ['mariadb', '5.5.5-10.11.6-MariaDB-log', 'mariadb', '10.11.6'],
    'mariadb 12' => ['mariadb', '12.3.1-MariaDB', 'mariadb', '12.3.1'],
    'garbage' => ['mysql', 'unknown', 'mysql', '0'],
]);

it('names the engine', function (): void {
    expect(ServerVersion::parse('pgsql', '17.2')->engine())->toBe('PostgreSQL')
        ->and(ServerVersion::parse('mysql', '8.0.35')->engine())->toBe('MySQL')
        ->and(ServerVersion::parse('mysql', '10.11.6-MariaDB')->engine())->toBe('MariaDB');
});
