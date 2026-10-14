<?php

declare(strict_types=1);

namespace App\Database;

use App\Auth\EmailAddress;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Brings the addresses already stored in a table to their canonical form
 * ({@see EmailAddress::normalize()}), for a migration that starts to rely on it.
 */
final class StoredEmails
{
    /**
     * Normalizes every address of the `users` table, or changes nothing and
     * fails when two addresses would become the same.
     *
     * @throws RuntimeException When different rows would end up with one address.
     */
    public static function normalize(): void
    {
        $owners = [];
        $duplicates = [];
        $changes = [];

        foreach (DB::table('users')->select(['id', 'email'])->orderBy('id')->lazyById(500) as $row) {
            $stored = (string) $row->email;
            $canonical = EmailAddress::normalize($stored);

            if (isset($owners[$canonical])) {
                $duplicates[$canonical] = array_merge($duplicates[$canonical] ?? [$owners[$canonical]], [$row->id]);
            }

            $owners[$canonical] ??= $row->id;

            if ($canonical !== $stored) {
                $changes[$row->id] = $canonical;
            }
        }

        if ($duplicates !== []) {
            $listed = [];

            foreach ($duplicates as $email => $ids) {
                $listed[] = $email.' (ids '.implode(', ', $ids).')';
            }

            throw new RuntimeException(
                'Cannot bring the email addresses of users to their canonical form (lowercase, domain in punycode): these rows would share an address: '
                .implode('; ', $listed).'. Merge or delete the duplicates, then run the migration again.'
            );
        }

        foreach ($changes as $id => $email) {
            DB::table('users')->where('id', $id)->update(['email' => $email]);
        }
    }
}
