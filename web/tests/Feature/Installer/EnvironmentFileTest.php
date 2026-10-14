<?php

declare(strict_types=1);

use App\Installer\EnvironmentFile;
use App\Installer\Exceptions\InstallationException;
use Dotenv\Dotenv;
use Tests\Support\FailingWriteStream;

/**
 * Builds an EnvironmentFile on a sandbox file holding the given contents.
 */
function environmentFile(object $test, ?string $contents = null, ?string $example = null): EnvironmentFile
{
    $path = $test->sandbox->root.'/env-under-test';
    $examplePath = $test->sandbox->root.'/env-example';

    if ($contents !== null) {
        file_put_contents($path, $contents);
    }

    file_put_contents($examplePath, $example ?? "APP_NAME=example\nAPP_ENV=local\n");

    return new EnvironmentFile($path, $examplePath);
}

it('updates an assignment in place and keeps everything else', function (): void {
    $file = environmentFile($this, "# Application\nAPP_NAME=inventaire\n\nDB_HOST=127.0.0.1 # old\n# DB_PORT=1\nDB_PORT=5432\n");

    $file->set(['DB_HOST' => 'db.internal', 'DB_PORT' => '3306']);

    expect(file_get_contents($file->path()))->toBe("# Application\nAPP_NAME=inventaire\n\nDB_HOST=db.internal\n# DB_PORT=1\nDB_PORT=3306\n");
});

it('appends missing keys after a blank line', function (): void {
    $file = environmentFile($this, "APP_NAME=inventaire\n");

    $file->set(['DOCUMENSO_URL' => 'https://sign.example.com', 'DOCUMENSO_API_KEY' => 'api_x']);

    expect(file_get_contents($file->path()))->toBe("APP_NAME=inventaire\n\nDOCUMENSO_URL=https://sign.example.com\nDOCUMENSO_API_KEY=api_x\n");
});

it('adds a final newline when the file lacks one', function (): void {
    $file = environmentFile($this, 'APP_NAME=inventaire');

    $file->set(['APP_ENV' => 'production']);

    expect(file_get_contents($file->path()))->toBe("APP_NAME=inventaire\n\nAPP_ENV=production\n");
});

it('rewrites the last occurrence of a duplicated key, which is the one Laravel uses', function (): void {
    $file = environmentFile($this, "APP_ENV=local\nAPP_ENV=staging\n");

    $file->set(['APP_ENV' => 'production']);

    expect($file->get('APP_ENV'))->toBe('production');
});

it('keeps the export prefix and the indentation of an assignment', function (): void {
    $file = environmentFile($this, "export APP_ENV=local\n  APP_DEBUG=true\n");

    $file->set(['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);

    expect(file_get_contents($file->path()))->toBe("export APP_ENV=production\n  APP_DEBUG=false\n");
});

it('preserves CRLF line endings', function (): void {
    $file = environmentFile($this, "APP_ENV=local\r\n# note\r\nAPP_DEBUG=true\r\n");

    $file->set(['APP_ENV' => 'production', 'APP_URL' => 'https://example.com']);

    expect(file_get_contents($file->path()))->toBe("APP_ENV=production\r\n# note\r\nAPP_DEBUG=true\r\n\r\nAPP_URL=https://example.com\r\n");
});

it('rewrites a multi-line quoted value as one assignment', function (): void {
    $file = environmentFile($this, "BEFORE=1\nCERT=\"line one\nline two\"\nAFTER=2\n");

    $file->set(['CERT' => 'single']);

    expect(file_get_contents($file->path()))->toBe("BEFORE=1\nCERT=single\nAFTER=2\n");
});

it('leaves a multi-line value alone when another key changes', function (): void {
    $file = environmentFile($this, "CERT=\"line one\nline two\"\nAFTER=2\n");

    $file->set(['AFTER' => '3']);

    expect($file->get('CERT'))->toBe("line one\nline two")->and($file->get('AFTER'))->toBe('3');
});

it('reads values back exactly as the dotenv parser Laravel uses', function (string $value): void {
    $file = environmentFile($this, "KEEP=1\n");

    $file->set(['TRICKY' => $value]);

    expect(Dotenv::parse(file_get_contents($file->path()))['TRICKY'])->toBe($value)
        ->and($file->get('TRICKY'))->toBe($value)
        ->and($file->get('KEEP'))->toBe('1');
})->with([
    'plain' => 'abc',
    'url' => 'https://example.com:8080/path?x=1',
    'spaces' => 'hello world',
    'hash' => 'pass#word',
    'dollar' => 'pa$$word',
    'variable look-alike' => '${APP_NAME}',
    'double quotes' => 'say "hi"',
    'single quotes' => "it's",
    'backslash' => 'C:\\temp\\file',
    'trailing backslash' => 'abc\\',
    'equals' => 'a=b=c',
    'newline' => "line1\nline2",
    'tab' => "a\tb",
    'unicode' => 'Café Éléphant ✓',
    'only spaces' => '   ',
    'mail from' => 'inventaire.cloud <hello@example.com>',
    'null word' => 'null',
    'trailing newline' => "abc\n",
    'only a newline' => "\n",
    'trailing CRLF' => "abc\r\n",
]);

it('writes an empty value as an empty assignment', function (): void {
    $file = environmentFile($this, "MAIL_USERNAME=old\n");

    $file->set(['MAIL_USERNAME' => '']);

    expect(file_get_contents($file->path()))->toBe("MAIL_USERNAME=\n")->and($file->get('MAIL_USERNAME'))->toBe('');
});

it('expands variables like Laravel when reading', function (): void {
    $file = environmentFile($this, "APP_NAME=inventaire\nMAIL_FROM_NAME=\"\${APP_NAME}\"\n");

    expect($file->get('MAIL_FROM_NAME'))->toBe('inventaire');
});

it('creates the file from the example when it does not exist', function (): void {
    $file = environmentFile($this, null, "# template\nAPP_NAME=example\nAPP_KEY=\n");

    expect($file->exists())->toBeFalse();

    $created = $file->set(['APP_KEY' => 'base64:abc=']);

    expect($created)->toBeTrue()
        ->and(file_get_contents($file->path()))->toBe("# template\nAPP_NAME=example\nAPP_KEY=base64:abc=\n")
        ->and($file->set(['APP_NAME' => 'x']))->toBeFalse();
});

it('applies the creation defaults only when it creates the file', function (): void {
    $file = environmentFile($this, null, "APP_ENV=local\nAPP_DEBUG=true\n");

    $file->set(['APP_KEY' => 'k'], EnvironmentFile::NEW_FILE_DEFAULTS);
    expect(file_get_contents($file->path()))->toStartWith("APP_ENV=production\nAPP_DEBUG=false\n\nAPP_KEY=k\n")
        // The development credentials and services of the example are cleared too, not pre-filled in the wizard.
        ->toContain("DB_USERNAME=\n")->toContain("DB_PASSWORD=\n")->toContain("MAIL_HOST=\n")->toContain("MAIL_FROM_ADDRESS=\n");

    file_put_contents($file->path(), "APP_ENV=local\n");
    $file->set(['APP_KEY' => 'k'], EnvironmentFile::NEW_FILE_DEFAULTS);
    expect(file_get_contents($file->path()))->toBe("APP_ENV=local\n\nAPP_KEY=k\n");

    // An explicit value wins over a creation default.
    unlink($file->path());
    $file->set(['APP_ENV' => 'staging'], EnvironmentFile::NEW_FILE_DEFAULTS);
    expect($file->get('APP_ENV'))->toBe('staging');
});

it('creates a file even when there is no example', function (): void {
    $file = new EnvironmentFile($this->sandbox->root.'/fresh', $this->sandbox->root.'/missing-example');

    $file->set(['A' => '1']);

    expect(file_get_contents($file->path()))->toBe("A=1\n");
});

it('replaces the file atomically without leaving temporary files behind', function (): void {
    $file = environmentFile($this, "A=1\n");
    chmod($file->path(), 0640);

    $file->set(['A' => '2']);

    expect(file_get_contents($file->path()))->toBe("A=2\n")
        ->and(fileperms($file->path()) & 0777)->toBe(0640)
        ->and(glob($this->sandbox->root.'/.tmp-*'))->toBe([]);
});

it('writes through a symbolic link instead of replacing it', function (): void {
    $real = $this->sandbox->root.'/real-env';
    file_put_contents($real, "A=1\n");
    symlink($real, $this->sandbox->root.'/linked-env');

    $file = new EnvironmentFile($this->sandbox->root.'/linked-env', $this->sandbox->root.'/none');
    $file->set(['A' => '2']);

    expect(is_link($this->sandbox->root.'/linked-env'))->toBeTrue()->and(file_get_contents($real))->toBe("A=2\n");
});

it('knows whether it can be written', function (): void {
    $file = environmentFile($this, "A=1\n");

    expect($file->isWritable())->toBeTrue();

    chmod($file->path(), 0444);

    if (posix_geteuid() !== 0) {
        expect($file->isWritable())->toBeFalse();
    }

    $missing = new EnvironmentFile($this->sandbox->root.'/no-such-dir/.env', $this->sandbox->root.'/none');

    expect($missing->isWritable())->toBeFalse();
});

it('rewrites in place when only the file, not its directory, is writable', function (): void {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('Directory permissions do not restrict root.');
    }

    $directory = $this->sandbox->root.'/mounted';
    mkdir($directory);
    file_put_contents($directory.'/.env', "A=1\n");
    chmod($directory, 0555);

    $file = new EnvironmentFile($directory.'/.env', $this->sandbox->root.'/none');
    $file->set(['A' => '2']);

    expect(file_get_contents($directory.'/.env'))->toBe("A=2\n");
});

it('fails with a clear message when the file cannot be written', function (): void {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('File permissions do not restrict root.');
    }

    $file = environmentFile($this, "A=1\n");
    chmod($file->path(), 0444);
    chmod($this->sandbox->root, 0555);

    $file->set(['A' => '2']);
})->throws(InstallationException::class);

it('treats an unreadable or invalid file as empty', function (): void {
    $missing = new EnvironmentFile($this->sandbox->root.'/absent', $this->sandbox->root.'/none');
    $invalid = environmentFile($this, "BROKEN=\"never closed\n");

    expect($missing->all())->toBe([])->and($invalid->all())->toBe([]);
});

describe('when the file has to be rewritten in place', function (): void {
    beforeEach(function (): void {
        stream_wrapper_register('failing', FailingWriteStream::class);
        FailingWriteStream::$files = ['failing://env' => "A=1\nSECRET=keep-me\n"];
    });

    afterEach(function (): void {
        stream_wrapper_unregister('failing');
        FailingWriteStream::$failNextWrite = false;
    });

    it('rewrites the file under a lock', function (): void {
        $file = new EnvironmentFile('failing://env', base_path('.env.example'));

        $file->set(['A' => '2']);

        expect(FailingWriteStream::$files['failing://env'])->toBe("A=2\nSECRET=keep-me\n");
    });

    it('restores the previous contents instead of leaving an empty file when the write fails', function (): void {
        $file = new EnvironmentFile('failing://env', base_path('.env.example'), $this->sandbox->root.'/env.backup');
        FailingWriteStream::$failNextWrite = true;

        try {
            $file->set(['A' => '2']);
        } catch (InstallationException $exception) {
            expect($exception->userMessage())->toContain('previous contents were restored');
            expect(FailingWriteStream::$files['failing://env'])->toBe("A=1\nSECRET=keep-me\n")
                // The backup file stays as a last resort after a failure...
                ->and(file_get_contents($this->sandbox->root.'/env.backup'))->toBe("A=1\nSECRET=keep-me\n");

            return;
        }

        $this->fail('Expected an InstallationException.');
    });

    it('does not keep the backup, which holds secrets, after a successful write', function (): void {
        $file = new EnvironmentFile('failing://env', base_path('.env.example'), $this->sandbox->root.'/env.backup');

        $file->set(['A' => '2']);

        expect(is_file($this->sandbox->root.'/env.backup'))->toBeFalse();
    });
});
