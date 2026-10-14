<?php

declare(strict_types=1);

use App\Installer\Mail\MailChallenge;
use App\Installer\Mail\MailCodeOutcome;

const CHALLENGE_KEY = 'base64:test-key';

/**
 * A challenge for the code 123456, valid for 15 minutes from time 1000.
 */
function challenge(): MailChallenge
{
    return MailChallenge::issue(['host' => 'smtp.example.com', 'password' => 'secret'], 'me@example.com', '123456', CHALLENGE_KEY, 15, 1000);
}

it('accepts the right code', function (): void {
    [$outcome, $kept] = challenge()->verify('123456', CHALLENGE_KEY, 5, 1001);

    expect($outcome)->toBe(MailCodeOutcome::Accepted)->and($kept->attempts)->toBe(0);
});

it('tolerates whitespace around the code', function (): void {
    expect(challenge()->verify(" 123456\n", CHALLENGE_KEY, 5, 1001)[0])->toBe(MailCodeOutcome::Accepted);
});

it('counts wrong codes and burns the challenge at the limit', function (): void {
    $current = challenge();

    foreach ([4, 3, 2, 1] as $left) {
        [$outcome, $current] = $current->verify('000000', CHALLENGE_KEY, 5, 1001);

        expect($outcome)->toBe(MailCodeOutcome::Wrong)->and($current->attemptsLeft(5))->toBe($left);
    }

    [$outcome, $current] = $current->verify('000000', CHALLENGE_KEY, 5, 1001);
    expect($outcome)->toBe(MailCodeOutcome::TooManyAttempts)->and($current->attemptsLeft(5))->toBe(0);

    // Even the right code is refused afterwards.
    expect($current->verify('123456', CHALLENGE_KEY, 5, 1001)[0])->toBe(MailCodeOutcome::TooManyAttempts);
});

it('expires after fifteen minutes', function (): void {
    expect(challenge()->verify('123456', CHALLENGE_KEY, 5, 1000 + 15 * 60)[0])->toBe(MailCodeOutcome::Accepted)
        ->and(challenge()->verify('123456', CHALLENGE_KEY, 5, 1000 + 15 * 60 + 1)[0])->toBe(MailCodeOutcome::Expired);
});

it('does not accept a code hashed with another key', function (): void {
    expect(challenge()->verify('123456', 'other-key', 5, 1001)[0])->toBe(MailCodeOutcome::Wrong);
});

it('never stores the code itself', function (): void {
    expect(challenge()->toPayload())->not->toContain('123456');
});

it('survives a round trip through its payload', function (): void {
    $restored = MailChallenge::fromPayload(challenge()->verify('nope', CHALLENGE_KEY, 5, 1001)[1]->toPayload());

    expect($restored)->not->toBeNull()
        ->and($restored->values)->toBe(['host' => 'smtp.example.com', 'password' => 'secret'])
        ->and($restored->recipient)->toBe('me@example.com')
        ->and($restored->attempts)->toBe(1)
        ->and($restored->verify('123456', CHALLENGE_KEY, 5, 1001)[0])->toBe(MailCodeOutcome::Accepted);
});

it('rejects a malformed payload', function (string $payload): void {
    expect(MailChallenge::fromPayload($payload))->toBeNull();
})->with(['not json' => 'nope', 'wrong shape' => '{"values":1}', 'empty object' => '{}', 'missing hash' => '{"values":[],"recipient":"a","expires_at":1,"attempts":0}']);
