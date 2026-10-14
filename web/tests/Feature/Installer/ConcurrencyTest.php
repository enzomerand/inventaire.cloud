<?php

declare(strict_types=1);

use App\Installer\EnvironmentFile;
use App\Installer\Exceptions\InstallationException;
use App\Installer\Exceptions\LockBusyException;
use App\Installer\InstallationFinalizer;
use App\Installer\InstallationState;
use App\Installer\InstallStep;
use App\Installer\Mail\MailAttemptStore;
use App\Installer\Mail\MailChallenge;
use App\Installer\Mail\MailCodeOutcome;
use App\Installer\Mail\MailVerification;
use App\Installer\Probes\MailProbe;
use App\Installer\Support\FileLock;

/**
 * Runs several PHP processes at once, each hammering one change of a shared file, and waits for all of them.
 *
 * @param  list<string>  $names
 */
function runInParallel(string $what, string $directory, array $names, int $iterations): void
{
    $processes = [];

    foreach ($names as $name) {
        $command = [PHP_BINARY, base_path('tests/Support/hammer.php'), $what, $directory, $name, (string) $iterations];
        $processes[] = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    }

    foreach ($processes as $process) {
        expect(proc_close($process))->toBe(0);
    }
}

describe('the file lock', function (): void {
    it('runs the callback and returns its value', function (): void {
        expect(FileLock::exclusively($this->sandbox->root.'/lock', fn (): string => 'done'))->toBe('done')
            ->and(fileperms($this->sandbox->root.'/lock') & 0777)->toBe(0640);
    });

    it('can be taken again by the code it is running', function (): void {
        $path = $this->sandbox->root.'/lock';

        $result = FileLock::exclusively($path, fn () => FileLock::exclusively($path, fn (): string => 'nested', 0.2), 0.2);

        expect($result)->toBe('nested');
    });

    it('is released after the callback, even when it throws', function (): void {
        $path = $this->sandbox->root.'/lock';

        expect(fn () => FileLock::exclusively($path, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
        expect(FileLock::exclusively($path, fn (): string => 'again', 0.2))->toBe('again');
    });

    it('fails loudly when somebody else holds it', function (): void {
        $path = $this->sandbox->root.'/lock';
        touch($path);
        $other = fopen($path, 'c');
        flock($other, LOCK_EX);
        $ran = false;

        try {
            FileLock::exclusively($path, function () use (&$ran): void {
                $ran = true;
            }, 0.1);
        } catch (LockBusyException $exception) {
            expect($ran)->toBeFalse()->and($exception->userMessage())->toContain('try again');

            return;
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
        }

        $this->fail('Expected a LockBusyException.');
    });

    it('never runs the callback unlocked when the lock file cannot be opened', function (): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Directory permissions do not restrict root.');
        }

        mkdir($this->sandbox->root.'/readonly', 0555);
        $ran = false;

        expect(function () use (&$ran): void {
            FileLock::exclusively($this->sandbox->root.'/readonly/lock', function () use (&$ran): void {
                $ran = true;
            });
        })->toThrow(InstallationException::class);
        expect($ran)->toBeFalse();
    });
});

describe('parallel writers', function (): void {
    it('do not lose a step recorded at the same time', function (): void {
        $directory = $this->sandbox->root.'/parallel-state';

        runInParallel('progress', $directory, array_map(static fn (InstallStep $step): string => $step->value, InstallStep::cases()), 150);

        $progress = (new InstallationState($directory))->progress();

        expect(array_values($progress))->each->toBe('done');
    });

    it('do not lose a change made to .env at the same time', function (): void {
        $directory = $this->sandbox->root.'/parallel-env';
        mkdir($directory, 0750);
        file_put_contents($directory.'/.env', "KEEP=1\n");
        $names = ['ONE', 'TWO', 'THREE', 'FOUR', 'FIVE'];

        runInParallel('env', $directory, $names, 40);

        $values = (new EnvironmentFile($directory.'/.env', $directory.'/.env'))->all();

        expect(array_keys($values))->toEqualCanonicalizing(['KEEP', ...$names])->and($values['ONE'])->toBe('39');
    });
});

describe('finalizing twice at once', function (): void {
    it('gives the second request a clean "already running" error', function (): void {
        $this->bootInstaller(installed: false);
        $path = app(InstallationState::class)->path('finalize.lock');
        touch($path);
        $other = fopen($path, 'c');
        flock($other, LOCK_EX);

        try {
            app(InstallationFinalizer::class)->finalize();
        } catch (InstallationException $exception) {
            expect($exception->userMessage())->toBe('The installation is already running.');

            return;
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
        }

        $this->fail('Expected an InstallationException.');
    });

    it('runs the migrations in isolated mode', function (): void {
        expect(file_get_contents(base_path('app/Installer/DatabaseMigrator.php')))->toContain("'--isolated' => 1");
    });
});

describe('the verification code attempts', function (): void {
    /**
     * A verification service counting attempts on disk, as in production.
     */
    $service = fn (): MailVerification => new MailVerification(app(MailProbe::class), config(), new MailAttemptStore(app(InstallationState::class)));

    it('count across requests that hold stale copies of the same challenge', function () use ($service): void {
        $this->bootInstaller(installed: false);
        $verification = $service();
        $challenge = MailChallenge::issue(['host' => 'h'], 'me@example.test', '123456', config('app.key'), 15);

        // Three parallel requests all start from the challenge as it was in their copy of the session.
        [, $first] = $verification->confirm($challenge, '000000');
        [, $second] = $verification->confirm($challenge, '000000');
        [, $third] = $verification->confirm($challenge, '000000');

        expect($first->attempts)->toBe(1)->and($second->attempts)->toBe(2)->and($third->attempts)->toBe(3);
    });

    it('cannot be reset by replaying an old copy of the challenge', function () use ($service): void {
        $this->bootInstaller(installed: false);
        $verification = $service();
        $challenge = MailChallenge::issue(['host' => 'h'], 'me@example.test', '123456', config('app.key'), 15);

        foreach (range(1, 4) as $attempt) {
            $verification->confirm($challenge, '000000');
        }

        [$outcome] = $verification->confirm($challenge, '000000');
        [$afterwards] = $verification->confirm($challenge, '123456');

        expect($outcome)->toBe(MailCodeOutcome::TooManyAttempts)->and($afterwards)->toBe(MailCodeOutcome::TooManyAttempts);
    });

    it('count each challenge on its own', function () use ($service): void {
        $this->bootInstaller(installed: false);
        $verification = $service();
        $one = MailChallenge::issue([], 'a@example.test', '111111', config('app.key'), 15);
        $two = MailChallenge::issue([], 'a@example.test', '222222', config('app.key'), 15);

        $verification->confirm($one, '000000');
        [, $kept] = $verification->confirm($two, '000000');

        expect($kept->attempts)->toBe(1);
    });
});

it('does not leave the progress half-written when interrupted', function (): void {
    // The progress file is replaced atomically: a reader always finds a complete JSON document.
    $directory = $this->sandbox->root.'/atomic-state';
    $state = new InstallationState($directory);

    foreach (InstallStep::cases() as $step) {
        $state->record($step);
        expect(json_decode((string) file_get_contents($state->path('progress.json')), true))->toBeArray();
    }

    expect(glob($directory.'/.tmp-*'))->toBe([]);
});
