<?php

declare(strict_types=1);

use App\Installer\Exceptions\InstallationException;
use App\Installer\Support\AtomicFile;

it('creates files readable by the group and directories traversable by it', function (): void {
    $path = $this->sandbox->root.'/a/b/file.json';

    AtomicFile::write($path, '{}');

    expect(fileperms($path) & 0777)->toBe(0640)
        ->and(fileperms($this->sandbox->root.'/a') & 0777)->toBe(0750)
        ->and(fileperms($this->sandbox->root.'/a/b') & 0777)->toBe(0750);
});

it('replaces a file without leaving temporary files', function (): void {
    $path = $this->sandbox->root.'/file';
    AtomicFile::write($path, 'one');
    AtomicFile::write($path, 'two');

    expect(file_get_contents($path))->toBe('two')->and(glob($this->sandbox->root.'/.tmp-*'))->toBe([]);
});

it('creates an empty file once and leaves an existing one alone', function (): void {
    $path = $this->sandbox->root.'/lock';

    AtomicFile::touch($path);
    file_put_contents($path, 'kept');
    AtomicFile::touch($path);

    expect(file_get_contents($path))->toBe('kept')->and(fileperms($path) & 0777)->toBe(0640);
});

it('keeps the group of the directory it writes into, when it can', function (): void {
    $directory = $this->sandbox->root.'/shared';
    mkdir($directory, 0750);

    AtomicFile::write($directory.'/file', 'x');

    expect(filegroup($directory.'/file'))->toBe(filegroup($directory));
});

it('can replace a file only when its owner is preserved', function (): void {
    $mine = $this->sandbox->root.'/mine';
    file_put_contents($mine, 'x');

    expect(AtomicFile::canPreserveOwnership($mine))->toBeTrue()
        ->and(AtomicFile::canPreserveOwnership($this->sandbox->root.'/does-not-exist'))->toBeTrue();

    // Somebody else's file: only root could replace it without taking it over.
    expect(AtomicFile::canPreserveOwnership('/etc/hosts'))->toBe(AtomicFile::isRoot());
});

it('shows paths relative to the application, never absolute ones', function (): void {
    expect(AtomicFile::display(base_path('storage/installer/progress.json')))->toBe('storage/installer/progress.json')
        ->and(AtomicFile::display('/somewhere/else/secret.json'))->toBe('secret.json');
});

it('fails with a message that does not reveal where the application lives', function (): void {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('Directory permissions do not restrict root.');
    }

    mkdir($this->sandbox->root.'/locked', 0555);

    try {
        AtomicFile::write($this->sandbox->root.'/locked/file', 'x');
    } catch (InstallationException $exception) {
        expect($exception->getMessage())->not->toContain($this->sandbox->root);

        return;
    }

    $this->fail('Expected an InstallationException.');
});
