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
 * limiter for 24 hours from the first request of a window, and a request counts from the moment it is made: a freeze with signers, and
 * the sending again of a request that failed (a request that was refused for another reason, or sent nothing, is not counted).
 *
 * Beyond the limit the API answers `429` with `Retry-After`, as it does for every pace it holds: this throws the exception the limiter
 * of the routes throws, which the application renders the same way (`code`: `too_many_requests`).
 */
final class SignatureQuota
{
    /** The seconds a request counts for: a day. */
    public const WINDOW = 86400;

    /**
     * Refuses a request for signatures when the workspace has made as many as it may today. Nothing is counted: see {@see self::spend()}.
     *
     * @throws ThrottleRequestsException When the workspace has used its requests of the day.
     */
    public function ensure(int $workspaceId): void
    {
        $limit = config()->integer('inventories.signature_requests_per_workspace_per_day');
        $key = $this->key($workspaceId);

        if (! RateLimiter::tooManyAttempts($key, $limit)) {
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
     * Counts a request for signatures that was made.
     */
    public function spend(int $workspaceId): void
    {
        RateLimiter::hit($this->key($workspaceId), self::WINDOW);
    }

    /**
     * The key of the count of a workspace.
     */
    private function key(int $workspaceId): string
    {
        return 'signature-requests:'.$workspaceId;
    }
}
