<?php

declare(strict_types=1);

/*
 * Accounts have no password: `User::getAuthPassword()` returns a fingerprint derived from the shared remember token
 * (see its docblock). The framework calls that value a password hash and offers helpers built on that idea; used on
 * this application they misbehave in ways no test of the happy path notices, so the code is not allowed to call them:
 *
 * - `logout()` of a guard (or `Auth::logout()`) renews the shared token, which signs the account out of EVERY device
 *   instead of this one: `logoutCurrentDevice()` is the call that does what is meant;
 * - `logoutOtherDevices()` checks a password that does not exist and rehashes it;
 * - the `password.confirm` middleware redirects to a "confirm your password" page that does not exist.
 */

/**
 * The PHP code of a source file without its comments, since the docblocks that warn about these calls name them.
 */
function sourceWithoutComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/**
 * The calls and names in a piece of PHP code that must not be used (see the top of this file).
 *
 * `$this->logout()` is not one of them: it is a class calling a method of its own (the framework's
 * AuthenticateSession has one, which signs the current device out).
 *
 * @return list<string>
 */
function forbiddenAuthenticationCalls(string $code): array
{
    preg_match_all('/(?<!\$this)(?:->|::)\s*logout\s*\(|logoutOtherDevices\s*\(|password\.confirm|RequirePassword\b/i', sourceWithoutComments($code), $matches);

    return $matches[0];
}

/**
 * Every PHP file under the directories of the application that run its authentication.
 *
 * @return array<string, string> Relative path => contents.
 */
function applicationSources(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['app', 'routes', 'bootstrap'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
    }

    return $files;
}

describe('the application', function (): void {
    it('reads the sources it checks', function (): void {
        expect(array_keys(applicationSources()))->toContain('app/Models/User.php')->toContain('routes/api.php')->toContain('bootstrap/app.php');
    });

    it('never signs out with logout(), nor with logoutOtherDevices(), nor asks to confirm a password', function (): void {
        $violations = [];

        foreach (applicationSources() as $path => $source) {
            foreach (forbiddenAuthenticationCalls($source) as $call) {
                $violations[] = "{$path}: {$call}";
            }
        }

        expect($violations)->toBe([], 'Accounts have no password: use logoutCurrentDevice() (see User::getAuthPassword()). Found: '.implode(' | ', $violations));
    });

    it('signs out of the current device with logoutCurrentDevice(), which is allowed', function (): void {
        $sources = applicationSources();

        expect(forbiddenAuthenticationCalls($sources['app/Http/Controllers/Api/V1/Auth/LogoutController.php']))->toBe([])
            ->and($sources['app/Http/Controllers/Api/V1/Auth/LogoutController.php'])->toContain('logoutCurrentDevice()');
    });
});

describe('the check itself', function (): void {
    it('finds the calls that sign every device out or assume a password', function (string $code): void {
        expect(forbiddenAuthenticationCalls("<?php {$code}"))->not->toBe([]);
    })->with([
        'a guard' => ['$guard->logout();'],
        'the facade' => ['Auth::logout();'],
        'a guard from the facade' => ['Auth::guard("web")->logout();'],
        'with a space' => ['$guard->logout ();'],
        'other devices' => ['$guard->logoutOtherDevices($password);'],
        'the facade for other devices' => ['Auth::logoutOtherDevices($password);'],
        'the middleware by its name' => ['Route::get("/x")->middleware("password.confirm");'],
        'the middleware as a class' => ['$route->middleware(RequirePassword::class);'],
    ]);

    it('lets the allowed calls, a method of its own, and the comments that warn about the others', function (string $code): void {
        expect(forbiddenAuthenticationCalls("<?php {$code}"))->toBe([]);
    })->with([
        'the current device' => ['$guard->logoutCurrentDevice();'],
        'the current device from the facade' => ['Auth::guard("web")->logoutCurrentDevice();'],
        'a method of its own' => ['$this->logout($request);'],
        'a comment' => ['// Auth::logout() would sign out every device'],
        'a block comment' => ['/* $guard->logoutOtherDevices() and password.confirm */ $x = 1;'],
        'a docblock' => ["/**\n * Not `->logout()`.\n */ function f() {}"],
    ]);
});
