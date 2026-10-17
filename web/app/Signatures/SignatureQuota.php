<?php

declare(strict_types=1);

namespace App\Signatures;

use App\Inventories\Versions\OfficialVersions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How many requests for signatures a workspace may make in a day (`inventories.signature_requests_per_workspace_per_day`, 20).
 *
 * Every request makes Documenso send an email to every signer, to addresses the person types: without a limit, the instance would be a
 * relay for anybody who can edit a workspace, and the per-person pace of freezing versions ({@see OfficialVersions}) is no protection
 * against several people of one workspace, or one person with several workspaces. The count is the workspace's, held in Laravel's rate
 * limiter for 24 hours from the first request of a window.
 *
 * **A request is reserved, not checked and then counted** ({@see self::reserve()}): the limiter counts it and says how many there are
 * in one atomic step, so that requests made at the same moment (several people of a workspace, several calls of one person) cannot all
 * pass a check made before any of them was counted. The price is that a request which then fails for a reason of its own (the
 * inventory turns out to hold as many versions as it may, a store that fails) has used up its place all the same, and that the
 * attempts that are refused are counted too, which changes nothing since the day only ends with the window. A request refused before
 * it is reserved (a signer who is no address, a workspace whose storage is full, an instance without Documenso) is not counted.
 *
 * Beyond the limit the API answers `429` with `Retry-After`, as it does for every pace it holds: this throws the exception the limiter
 * of the routes throws, which the application renders the same way (`code`: `too_many_requests`).
 */
final class SignatureQuota
{
    /** The seconds a request counts for: a day. */
    public const WINDOW = 86400;

    /**
     * Reserves one request for signatures for the workspace, and refuses it when the workspace has made as many as it may today. The
     * count and the check are one atomic step of the rate limiter: of requests made at the same time, only as many as there are places
     * left are accepted. A request that is accepted and then fails keeps its place (it is not given back).
     *
     * @throws ThrottleRequestsException When the workspace has used its requests of the day.
     */
    public function reserve(int $workspaceId): void
    {
        $limit = config()->integer('inventories.signature_requests_per_workspace_per_day');
        $key = $this->key($workspaceId);

        if (RateLimiter::hit($key, self::WINDOW) <= $limit) {
            return;
        }

        $retryAfter = RateLimiter::availableIn($key);

        throw new ThrottleRequestsException('Too many requests for signatures.', null, [
            'X-RateLimit-Limit' => $limit,
            'X-RateLimit-Remaining' => 0,
            'Retry-After' => $retryAfter,
            'X-RateLimit-Reset' => time() + $retryAfter,
        ]);
    }

    /**
     * The key of the count of a workspace.
     */
    private function key(int $workspaceId): string
    {
        return 'signature-requests:'.$workspaceId;
    }
}
