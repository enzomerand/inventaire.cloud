<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Http\Responses\TooManyRequests;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How many photo analyses a person may ask for in a day (`inventories.photo_analyses_per_day`, 20).
 *
 * Every analysis is a paid request to Anthropic, billed to the key of the instance: without a limit, anybody who can edit a workspace
 * that turned the photo entry on could run up the bill. The count is the person's, in all their workspaces, held in Laravel's rate
 * limiter for 24 hours from the first analysis of a window.
 *
 * **Only an analysis that is accepted counts**: the request is first checked without counting ({@see self::ensureAvailable()}, so that a
 * person who is out of analyses is told before their photos are worked on), then the photos are prepared, and only then is the place
 * reserved ({@see self::reserve()}): a photo that cannot be read, a workspace that has the feature off, an inventory with no field to
 * fill, cost nothing. The reservation is one atomic step of the limiter, so requests made at the same moment cannot all pass a check
 * made before any of them was counted; one that is accepted and then fails (the disk, say) keeps its place.
 *
 * Beyond the limit the API answers `429` with `Retry-After`, as it does for every pace it holds: this throws the exception the limiter of
 * the routes throws, which the application renders the same way (`code`: `too_many_requests`).
 */
final class PhotoAnalysisQuota
{
    /** The seconds an analysis counts for: a day. */
    public const WINDOW = 86400;

    /**
     * Refuses the person who has used up their analyses of the day, without counting anything.
     *
     * @throws ThrottleRequestsException When the person has asked for as many analyses as they may today.
     */
    public function ensureAvailable(User $user): void
    {
        $key = $this->key($user);

        if (RateLimiter::tooManyAttempts($key, $this->limit())) {
            throw $this->refusal($key);
        }
    }

    /**
     * Reserves one analysis for the person, and refuses it when they have asked for as many as they may today. The count and the check
     * are one atomic step of the rate limiter.
     *
     * @throws ThrottleRequestsException When the person has used up their analyses of the day.
     */
    public function reserve(User $user): void
    {
        $key = $this->key($user);

        if (RateLimiter::hit($key, self::WINDOW) > $this->limit()) {
            throw $this->refusal($key);
        }
    }

    /**
     * What the person is told when the day is used up.
     */
    private function refusal(string $key): ThrottleRequestsException
    {
        return TooManyRequests::exception('Too many photo analyses today.', $this->limit(), RateLimiter::availableIn($key));
    }

    /**
     * The analyses a person may ask for in a day.
     */
    private function limit(): int
    {
        return config()->integer('inventories.photo_analyses_per_day');
    }

    /**
     * The key of the count of a person.
     */
    private function key(User $user): string
    {
        return 'photo-analyses-per-day:'.$user->getKey();
    }
}
