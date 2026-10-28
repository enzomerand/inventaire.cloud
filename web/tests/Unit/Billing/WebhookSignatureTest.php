<?php

declare(strict_types=1);

use App\Billing\WebhookSignature;
use Tests\Support\FakeStripe;

/*
 * The signature Stripe puts on a webhook: `t=<timestamp>,v1=<HMAC-SHA256 of "<timestamp>.<body>" keyed with the signing secret>`.
 */

const BILLING_SIGNING_SECRET = 'whsec_unit_secret';
const BILLING_NOW = 1_800_000_000;

/**
 * The header Stripe would send for a body, signed at a time.
 */
function billingSignature(string $payload, string $secret = BILLING_SIGNING_SECRET, int $at = BILLING_NOW, string $scheme = 'v1'): string
{
    return "t={$at},{$scheme}=".hash_hmac('sha256', "{$at}.{$payload}", $secret);
}

describe('a signature', function (): void {
    it('is valid when the secret signed that body a moment ago', function (): void {
        $payload = '{"id":"evt_1","type":"invoice.paid"}';

        expect(WebhookSignature::verify($payload, billingSignature($payload), BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeTrue();
    });

    it('is valid as Stripe signs it, which is how the fake of the tests signs it too', function (): void {
        [$payload, $header] = FakeStripe::signedEvent(FakeStripe::event('invoice.paid', ['customer' => 'cus_1']), 'whsec_x');

        expect(WebhookSignature::verify($payload, $header, 'whsec_x'))->toBeTrue()
            ->and(WebhookSignature::verify($payload, $header, 'whsec_y'))->toBeFalse();
    });

    it('is signed over the timestamp as written, not over the number it stands for', function (): void {
        $payload = '{"id":"evt_1"}';
        $written = '0'.BILLING_NOW;
        $signed = fn (string $timestamp): string => "t={$written},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", BILLING_SIGNING_SECRET);

        // What Stripe signed is the text it wrote in the header, leading zero included.
        expect(WebhookSignature::verify($payload, $signed($written), BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeTrue()
            // A signature of the number alone is not what Stripe sends for that header.
            ->and(WebhookSignature::verify($payload, $signed((string) BILLING_NOW), BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse();
    });

    it('is refused for another secret', function (): void {
        $payload = '{"id":"evt_1"}';

        expect(WebhookSignature::verify($payload, billingSignature($payload, 'whsec_other'), BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse();
    });

    it('is refused when the body changed by a single byte', function (): void {
        $header = billingSignature('{"id":"evt_1"}');

        expect(WebhookSignature::verify('{"id":"evt_2"}', $header, BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse()
            ->and(WebhookSignature::verify('{"id":"evt_1"} ', $header, BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse()
            ->and(WebhookSignature::verify('', $header, BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse();
    });

    it('is refused when the timestamp was changed to freshen an old signature', function (): void {
        $payload = '{"id":"evt_1"}';
        $old = billingSignature($payload, BILLING_SIGNING_SECRET, BILLING_NOW - 3600);
        $forged = preg_replace('/^t=\d+/', 't='.BILLING_NOW, $old);

        expect(WebhookSignature::verify($payload, $forged, BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse();
    });

    it('is refused for an empty secret, whatever the header says', function (): void {
        $payload = '{"id":"evt_1"}';

        expect(WebhookSignature::verify($payload, billingSignature($payload, ''), '', 300, BILLING_NOW))->toBeFalse();
    });
});

describe('the time of a signature', function (): void {
    it('may be a little before or after the clock, up to the tolerance', function (int $skew): void {
        $payload = '{"id":"evt_1"}';

        expect(WebhookSignature::verify($payload, billingSignature($payload, BILLING_SIGNING_SECRET, BILLING_NOW + $skew), BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeTrue();
    })->with([0, 1, -1, 299, -299, 300, -300]);

    it('may not be further than the tolerance, in either direction', function (int $skew): void {
        $payload = '{"id":"evt_1"}';

        expect(WebhookSignature::verify($payload, billingSignature($payload, BILLING_SIGNING_SECRET, BILLING_NOW + $skew), BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse();
    })->with([301, -301, 3600, -3600, 86400]);

    it('is held to the tolerance that is given', function (): void {
        $payload = '{"id":"evt_1"}';
        $header = billingSignature($payload, BILLING_SIGNING_SECRET, BILLING_NOW - 60);

        expect(WebhookSignature::verify($payload, $header, BILLING_SIGNING_SECRET, 30, BILLING_NOW))->toBeFalse()
            ->and(WebhookSignature::verify($payload, $header, BILLING_SIGNING_SECRET, 60, BILLING_NOW))->toBeTrue();
    });

    it('is compared with the clock when no time is given', function (): void {
        $payload = '{"id":"evt_1"}';

        expect(WebhookSignature::verify($payload, billingSignature($payload, BILLING_SIGNING_SECRET, time()), BILLING_SIGNING_SECRET))->toBeTrue()
            ->and(WebhookSignature::verify($payload, billingSignature($payload, BILLING_SIGNING_SECRET, time() - 1000), BILLING_SIGNING_SECRET))->toBeFalse();
    });
});

describe('a header with several signatures', function (): void {
    it('is valid when any v1 matches, wherever it stands (a secret being rotated)', function (): void {
        $payload = '{"id":"evt_1"}';
        $good = 'v1='.hash_hmac('sha256', BILLING_NOW.".{$payload}", BILLING_SIGNING_SECRET);
        $bad = 'v1='.hash_hmac('sha256', BILLING_NOW.".{$payload}", 'whsec_retired');

        expect(WebhookSignature::verify($payload, 't='.BILLING_NOW.",{$bad},{$good}", BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeTrue()
            ->and(WebhookSignature::verify($payload, 't='.BILLING_NOW.",{$good},{$bad}", BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeTrue()
            ->and(WebhookSignature::verify($payload, "{$bad},{$good},t=".BILLING_NOW, BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeTrue();
    });

    it('is refused when none matches', function (): void {
        $payload = '{"id":"evt_1"}';
        $bad = 'v1='.hash_hmac('sha256', BILLING_NOW.".{$payload}", 'whsec_retired');
        $worse = 'v1='.str_repeat('0', 64);

        expect(WebhookSignature::verify($payload, 't='.BILLING_NOW.",{$bad},{$worse}", BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse();
    });

    it('ignores the other schemes, the v0 of the test events included', function (): void {
        $payload = '{"id":"evt_1"}';
        $v0 = 'v0='.hash_hmac('sha256', BILLING_NOW.".{$payload}", BILLING_SIGNING_SECRET);
        $v1 = 'v1='.hash_hmac('sha256', BILLING_NOW.".{$payload}", BILLING_SIGNING_SECRET);

        expect(WebhookSignature::verify($payload, 't='.BILLING_NOW.",{$v0},{$v1}", BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeTrue()
            ->and(WebhookSignature::verify($payload, 't='.BILLING_NOW.",{$v0}", BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse()
            ->and(WebhookSignature::verify($payload, billingSignature($payload, BILLING_SIGNING_SECRET, BILLING_NOW, 'v0'), BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse();
    });
});

describe('a header that is not one', function (): void {
    it('is refused', function (?string $header): void {
        expect(WebhookSignature::verify('{"id":"evt_1"}', $header, BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse();
    })->with([
        'none' => [null],
        'empty' => [''],
        'garbage' => ['garbage'],
        'a timestamp alone' => ['t='.BILLING_NOW],
        'a signature alone' => ['v1='.str_repeat('a', 64)],
        'no timestamp value' => ['t=,v1='.str_repeat('a', 64)],
        'a timestamp that is not a number' => ['t=yesterday,v1='.str_repeat('a', 64)],
        'a negative timestamp' => ['t=-5,v1='.str_repeat('a', 64)],
        'a timestamp with a decimal' => ['t=1800000000.5,v1='.str_repeat('a', 64)],
        'a timestamp too big to be one' => ['t='.str_repeat('9', 40).',v1='.str_repeat('a', 64)],
        'a signature with no value' => ['t='.BILLING_NOW.',v1='],
        'a part without an equal sign' => ['t='.BILLING_NOW.',v1,'.str_repeat('a', 64)],
        'a trailing comma' => ['t='.BILLING_NOW.',v1='.str_repeat('a', 64).','],
        'two timestamps' => ['t='.BILLING_NOW.',t='.BILLING_NOW.',v1='.str_repeat('a', 64)],
    ]);

    it('is refused even when its signature is the right one but the timestamp is written twice', function (): void {
        $payload = '{"id":"evt_1"}';
        $header = billingSignature($payload).',t='.(BILLING_NOW - 1);

        expect(WebhookSignature::verify($payload, $header, BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse();
    });

    it('may carry spaces around its parts', function (): void {
        $payload = '{"id":"evt_1"}';
        $at = BILLING_NOW;
        $header = "t={$at}, v1=".hash_hmac('sha256', "{$at}.{$payload}", BILLING_SIGNING_SECRET);

        expect(WebhookSignature::verify($payload, $header, BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeTrue();
    });

    it('is not fooled by a signature written in capitals, which Stripe never does', function (): void {
        $payload = '{"id":"evt_1"}';
        $header = 't='.BILLING_NOW.',v1='.strtoupper(hash_hmac('sha256', BILLING_NOW.".{$payload}", BILLING_SIGNING_SECRET));

        expect(WebhookSignature::verify($payload, $header, BILLING_SIGNING_SECRET, 300, BILLING_NOW))->toBeFalse();
    });
});
