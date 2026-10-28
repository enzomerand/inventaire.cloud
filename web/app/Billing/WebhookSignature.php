<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Checks that a webhook really comes from Stripe: the `Stripe-Signature` header carries a timestamp and one or more signatures
 * (`t=1492774577,v1=5257a869…,v1=…,v0=…`), each the HMAC-SHA256, in hexadecimal, of `"{t}.{raw body}"` keyed with the signing secret of
 * the endpoint (`whsec_…`). Several `v1` appear while a secret is being rotated; any of them may match. Other schemes (`v0`, the one of
 * the test events) are ignored.
 *
 * The body must be the raw one, byte for byte as it arrived: a JSON that was decoded and encoded again does not match. The timestamp
 * must be within the tolerance of the clock, in both directions, so that a captured request cannot be replayed later.
 */
final class WebhookSignature
{
    /** How many seconds the timestamp of a signature may differ from the clock. */
    public const TOLERANCE = 300;

    /**
     * Whether the header is a valid signature of the payload by the secret, and a recent one.
     *
     * @param  string  $payload  The raw body of the request.
     * @param  string|null  $header  The `Stripe-Signature` header; null when the request had none.
     * @param  string  $secret  The signing secret of the endpoint; an empty one verifies nothing.
     * @param  int  $tolerance  The seconds the timestamp may be away from now, before or after.
     * @param  int|null  $now  The time to compare with, as a Unix timestamp; the clock when null.
     */
    public static function verify(string $payload, ?string $header, string $secret, int $tolerance = self::TOLERANCE, ?int $now = null): bool
    {
        if ($header === null || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signed = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                return false;
            }

            if ($pair[0] === 't') {
                // One timestamp, digits only: anything else is a header that was not made by Stripe.
                if ($timestamp !== null || preg_match('/^\d{1,11}\z/', $pair[1]) !== 1) {
                    return false;
                }

                $timestamp = (int) $pair[1];
                // What is signed is the text Stripe wrote, not the number it stands for (`0001492774577` is not `1492774577`).
                $signed = $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }

        if ($timestamp === null || $signed === null || $signatures === [] || abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $signed.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
