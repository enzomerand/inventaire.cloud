<?php

declare(strict_types=1);

use App\Installer\Probes\FilesystemStorageProbe;
use App\Installer\Probes\StorageProbe;
use App\Installer\Settings\StorageSettings;

it('is bound to the real filesystem probe', function (): void {
    expect(app(StorageProbe::class))->toBeInstanceOf(FilesystemStorageProbe::class);
});

it('writes, reads and deletes a file on the local disk', function (): void {
    $result = app(StorageProbe::class)->check(new StorageSettings('local'));

    expect($result->ok)->toBeTrue()->and(glob($this->sandbox->root.'/storage/.installer-probe-*'))->toBe([]);
});

it('fails when the local disk is not writable', function (): void {
    // A root that is a regular file can neither hold nor create files.
    file_put_contents($this->sandbox->root.'/not-a-directory', 'x');
    config(['filesystems.disks.local.root' => $this->sandbox->root.'/not-a-directory']);

    $result = app(StorageProbe::class)->check(new StorageSettings('local'));

    expect($result->ok)->toBeFalse()->and($result->field)->toBe('disk')->and($result->message)->toContain('did not accept');
});

it('fails on an unreachable S3 endpoint without leaking the keys', function (): void {
    $settings = new StorageSettings('s3', 'AKIAEXAMPLEKEY', 'wJalrExampleSecret', 'us-east-1', 'bucket', 'http://127.0.0.1:1', true);

    $result = app(StorageProbe::class)->check($settings);

    expect($result->ok)->toBeFalse()
        ->and($result->field)->toBe('bucket')
        ->and($result->message)->not->toContain('wJalrExampleSecret')->not->toContain('AKIAEXAMPLEKEY');
});

it('builds the S3 disk from the settings', function (): void {
    $config = (new StorageSettings('s3', 'key', 'secret', 'eu-west-3', 'photos', 'https://s3.example.test', true))->diskConfig([]);

    expect($config)->toMatchArray([
        'driver' => 's3', 'key' => 'key', 'secret' => 'secret', 'region' => 'eu-west-3', 'bucket' => 'photos',
        'endpoint' => 'https://s3.example.test', 'use_path_style_endpoint' => true, 'throw' => true,
    ]);
});

it('uses the application local disk as is for local storage', function (): void {
    expect((new StorageSettings('local'))->diskConfig(['driver' => 'local', 'root' => '/data']))->toBe(['driver' => 'local', 'root' => '/data', 'throw' => true]);
});

it('reads the storage step values', function (): void {
    $settings = StorageSettings::fromArray(['disk' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'r', 'bucket' => 'b', 'endpoint' => '', 'path_style' => 'true']);

    expect($settings->isS3())->toBeTrue()->and($settings->endpoint)->toBeNull()->and($settings->pathStyle)->toBeTrue()
        ->and(StorageSettings::fromArray([])->disk)->toBe('local');
});
