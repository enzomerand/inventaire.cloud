<?php

declare(strict_types=1);

use App\Installer\InstallationState;
use App\Installer\Mail\MailAttemptStore;
use App\Installer\Mail\MailChallenge;
use App\Installer\Mail\MailCodeOutcome;
use App\Installer\Mail\MailVerification;
use App\Installer\Probes\MailProbe;

/**
 * A challenge id as random_bytes() can produce it: sixteen hexadecimal characters that happen to be all digits.
 */
const ALL_DIGIT_CHALLENGE_ID = '1234567890123456';

beforeEach(function (): void {
    $this->bootInstaller(installed: false);
    $this->store = new MailAttemptStore(app(InstallationState::class));
});

it('counts the attempts of a challenge whose id is made only of digits', function (): void {
    expect($this->store->count(ALL_DIGIT_CHALLENGE_ID))->toBe(0);

    $this->store->record(ALL_DIGIT_CHALLENGE_ID, 3);
    expect($this->store->count(ALL_DIGIT_CHALLENGE_ID))->toBe(3);

    $this->store->record(ALL_DIGIT_CHALLENGE_ID, 4);
    expect($this->store->count(ALL_DIGIT_CHALLENGE_ID))->toBe(4);
});

it('keeps challenges apart, whatever their ids look like', function (): void {
    $this->store->record(ALL_DIGIT_CHALLENGE_ID, 2);
    $this->store->record('0000000000000001', 1);
    $this->store->record('deadbeefdeadbeef', 5);

    expect($this->store->count(ALL_DIGIT_CHALLENGE_ID))->toBe(2)
        ->and($this->store->count('0000000000000001'))->toBe(1)
        ->and($this->store->count('deadbeefdeadbeef'))->toBe(5)
        ->and($this->store->count('1'))->toBe(0);
});

it('stores every key as a string, so no id is turned into an integer', function (): void {
    $this->store->record(ALL_DIGIT_CHALLENGE_ID, 1);
    $this->store->record('42', 2);

    $stored = json_decode((string) file_get_contents(app(InstallationState::class)->path('mail-attempts.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($stored)->toHaveCount(2);

    foreach (array_keys($stored) as $key) {
        expect($key)->toBeString()->not->toMatch('/^\d+$/');
    }
});

it('forgets the oldest challenges beyond the ones it keeps, digits or not', function (): void {
    foreach (range(1, 25) as $number) {
        $this->store->record((string) $number, 1);
    }

    expect($this->store->count('25'))->toBe(1)->and($this->store->count('6'))->toBe(1)->and($this->store->count('5'))->toBe(0);
});

it('enforces the attempt limit on a challenge whose id is made only of digits', function (): void {
    $verification = new MailVerification(app(MailProbe::class), config(), $this->store);
    $issued = MailChallenge::issue(['host' => 'h'], 'me@example.test', '123456', config('app.key'), 15);
    $challenge = new MailChallenge($issued->values, $issued->recipient, $issued->codeHash, $issued->expiresAt, 0, ALL_DIGIT_CHALLENGE_ID);

    // Every guess starts from the stale copy of the session, as parallel requests do: only the store can count them.
    foreach (range(1, 5) as $guess) {
        $verification->confirm($challenge, '000000');
    }

    [$outcome] = $verification->confirm($challenge, '123456');

    expect($outcome)->toBe(MailCodeOutcome::TooManyAttempts);
});
