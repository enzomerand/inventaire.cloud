<?php

declare(strict_types=1);

namespace App\Signatures;

use App\Auth\EmailAddress;
use App\Inventories\Versions\SignerStatus;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Brings the signers kept with a version up to date with what Documenso says of its recipients.
 *
 * A signer is matched to a recipient by email address, both in their canonical form (Documenso may write an address in another case).
 * The recipient gives the signer their `recipient_id` and their state: `NOT_SIGNED` is `pending`, `SIGNED` is `signed` (with the date
 * Documenso gives, in UTC) and `REJECTED` is `rejected` (with the reason, when one was given). A signer with no recipient, and anything
 * else kept with the signer (the place of their signature box), is left as it is. Documenso's word is final: a recipient that is
 * `NOT_SIGNED` makes the signer `pending` again.
 */
final class SignerStates
{
    /**
     * The signers as Documenso's recipients say they are.
     *
     * @param  list<array<string, mixed>>  $signers  As kept with the version.
     * @param  mixed  $recipients  The `recipients` of the envelope as Documenso gave them; anything that is not a list of recipients changes nothing.
     * @return list<array<string, mixed>>
     */
    public static function merge(array $signers, mixed $recipients): array
    {
        $byEmail = [];

        foreach (is_array($recipients) ? $recipients : [] as $recipient) {
            if (is_array($recipient) && is_string($recipient['email'] ?? null)) {
                $byEmail[EmailAddress::normalize($recipient['email'])] = $recipient;
            }
        }

        $merged = [];

        foreach ($signers as $signer) {
            $recipient = is_string($signer['email'] ?? null) ? ($byEmail[EmailAddress::normalize($signer['email'])] ?? null) : null;

            $merged[] = $recipient === null ? $signer : self::apply($signer, $recipient);
        }

        return $merged;
    }

    /**
     * One signer, as their recipient says they are.
     *
     * @param  array<string, mixed>  $signer
     * @param  array<array-key, mixed>  $recipient
     * @return array<string, mixed>
     */
    private static function apply(array $signer, array $recipient): array
    {
        $id = $recipient['id'] ?? null;

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $signer['recipient_id'] = (int) $id;
        }

        $status = match ($recipient['signingStatus'] ?? null) {
            'SIGNED' => SignerStatus::Signed,
            'REJECTED' => SignerStatus::Rejected,
            'NOT_SIGNED' => SignerStatus::Pending,
            default => null,
        };

        if ($status === null) {
            return $signer;
        }

        $signer['status'] = $status->value;
        $signer['signed_at'] = $status === SignerStatus::Signed ? self::date($recipient['signedAt'] ?? null) : null;
        $reason = $recipient['rejectionReason'] ?? null;
        $signer['rejection_reason'] = $status === SignerStatus::Rejected && is_string($reason) && trim($reason) !== '' ? mb_substr(trim($reason), 0, 1000) : null;

        return $signer;
    }

    /**
     * A date Documenso gave, as an ISO 8601 text in UTC; null when it gave none or one that cannot be read.
     */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
