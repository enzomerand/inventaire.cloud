<?php

declare(strict_types=1);

use App\Installer\InstallationState;
use App\Installer\SetupToken;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

/**
 * A token over a fresh state directory of the sandbox.
 */
function freshToken(object $test): SetupToken
{
    return new SetupToken(new InstallationState($test->sandbox->root.'/token-state'));
}

it('creates a random token readable by its owner only', function (): void {
    $token = freshToken($this);

    expect($token->ensure())->toBeTrue()
        ->and($token->ensure())->toBeFalse()
        ->and(fileperms($token->path()) & 0777)->toBe(0600)
        ->and($token->current())->toMatch('/^[0-9a-f]{32}$/')
        ->and(freshToken($this)->current())->toBe($token->current());
});

it('does not create a token just to answer a verification', function (): void {
    $token = freshToken($this);

    expect($token->verify('anything'))->toBeFalse()->and(is_file($token->path()))->toBeFalse()->and($token->fingerprint())->toBeNull();
});

it('accepts only the exact token', function (): void {
    $token = freshToken($this);
    $value = $token->current();

    expect($token->verify($value))->toBeTrue()
        ->and($token->verify("  {$value}\n"))->toBeTrue()
        ->and($token->verify(strtoupper($value)))->toBeFalse()
        ->and($token->verify(substr($value, 0, -1)))->toBeFalse()
        ->and($token->verify(''))->toBeFalse();
});

it('compares in constant time', function (): void {
    expect(file_get_contents(base_path('app/Installer/SetupToken.php')))->toContain('hash_equals(');
});

it('tells which session entered the current token and regenerates its id', function (): void {
    $token = freshToken($this);
    $token->ensure();
    $session = new Store('test', new ArraySessionHandler(120));
    $session->start();
    $before = $session->getId();

    expect($token->isAuthorized($session))->toBeFalse();

    $token->authorize($session);

    expect($token->isAuthorized($session))->toBeTrue()->and($session->getId())->not->toBe($before);
});

it('revokes authorized sessions when the token is deleted or replaced', function (): void {
    $token = freshToken($this);
    $token->ensure();
    $session = new Store('test', new ArraySessionHandler(120));
    $session->start();
    $token->authorize($session);

    $token->forget();
    expect($token->isAuthorized($session))->toBeFalse()->and(is_file($token->path()))->toBeFalse();

    $token->ensure();
    expect($token->isAuthorized($session))->toBeFalse();
});
