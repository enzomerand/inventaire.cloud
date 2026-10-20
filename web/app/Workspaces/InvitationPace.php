<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Auth\EmailAddress;
use App\Http\Responses\TooManyRequests;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How many invitation mails one address may receive in a day (`workspaces.invitations_per_address_per_day`, 5), from every owner of the
 * instance together.
 *
 * An invitation mails an address the owner types, so a script of owners (or one owner with many workspaces) could make the application
 * flood a mailbox; the pace per owner ({@see Invitations}, the `workspace-invitations` limiter of the routes) does not stop that. The
 * count is the address's, kept under a hash of its normalized form so that the cache holds no address, for 24 hours from the first mail of
 * a window.
 *
 * **Only a mail that is sent is counted** ({@see self::record()}), and the check ({@see self::ensureRoom()}) is made once everything else
 * has been judged: a request that is refused for another reason (not the owner, a workspace that is not there, an address that is not
 * valid, a member already, an invitation already waiting) leaves the count alone. Otherwise anybody who can make such a request, an
 * ordinary member of any workspace included, could use up the day of an address and keep the real invitations to it out for good.
 * The price is that requests made at the very same moment may all pass a check made before any of them was counted: the limit is a
 * guard against flooding, not an exact count.
 *
 * Beyond the limit the API answers `429` with `Retry-After`, as it does for every pace it holds: this throws the exception the limiters of
 * the routes throw, which the application renders the same way (`code`: `too_many_requests`).
 */
final class InvitationPace
{
    /** The seconds a mail counts for: a day. */
    public const WINDOW = 86400;

    /**
     * Refuses a mail to an address that has received as many as it may today. Nothing is counted.
     *
     * @param  string  $email  The address, in any case.
     *
     * @throws ThrottleRequestsException When the address has had its mails of the day.
     */
    public function ensureRoom(string $email): void
    {
        $limit = config()->integer('workspaces.invitations_per_address_per_day');
        $key = $this->key($email);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw TooManyRequests::exception('Too many invitations to this address.', $limit, RateLimiter::availableIn($key));
        }
    }

    /**
     * Counts a mail that is sent to the address.
     *
     * @param  string  $email  The address, in any case.
     */
    public function record(string $email): void
    {
        RateLimiter::hit($this->key($email), self::WINDOW);
    }

    /**
     * The key of the count of an address: a hash, never the address.
     */
    private function key(string $email): string
    {
        return 'workspace-invitations-address:'.hash('sha256', EmailAddress::normalize($email));
    }
}
