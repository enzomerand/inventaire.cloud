<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Http\Responses\TooManyRequests;
use App\Models\User;
use App\PhotoEntry\Exceptions\InstanceQuotaReachedException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How many photo analyses a person may ask for in a day (`inventories.photo_analyses_per_day`, 20), and how many the whole instance
 * runs in a day (`inventories.photo_analyses_per_day_instance`, 200; 0 is no cap).
 *
 * Every analysis is a paid request to Anthropic, billed to the key of the instance: without a limit, anybody who can edit a workspace
 * that turned the photo entry on could run up the bill. The count is the person's, in all their workspaces, held in Laravel's rate
 * limiter for 24 hours from the first analysis of a window. The cap of the instance is the same count for everybody: twenty people with
 * twenty analyses each are four hundred requests, and the limit of each person alone would not stop the bill of the instance.
 *
 * **Only an analysis that is accepted counts**: the request is first checked without counting ({@see self::ensureAvailable()}, so that a
 * person who is out of analyses is told before their photos are worked on), then the photos are prepared, and only then is the place
 * reserved ({@see self::reserve()}): a photo that cannot be read, a workspace that has the feature off, an inventory with no field to
 * fill, cost nothing. The reservation is one atomic step of the limiter for each count, so requests made at the same moment cannot all
 * pass a check made before any of them was counted. An analysis that is accepted and then fails after Claude was asked keeps its place
 * (the request was paid for); one that never reached Anthropic (the photos could not be written, the job not queued, the consent
 * withdrawn while it waited) gives its places back ({@see self::release()}), and so does a person who is refused because the instance
 * is out of analyses: it was not used.
 *
 * Beyond the limit of the person the API answers `429` with `Retry-After`, as it does for every pace it holds: this throws the exception
 * the limiter of the routes throws, which the application renders the same way (`code`: `too_many_requests`). Beyond the cap of the
 * instance it is {@see InstanceQuotaReachedException}, which the API renders as a `429` of its own (`code`:
 * `photo_analyses_instance_limit`, and a message that says it is the instance that is out of analyses).
 */
final class PhotoAnalysisQuota
{
    /** The seconds an analysis counts for: a day. */
    public const WINDOW = 86400;

    /** The key of the count of the whole instance. */
    private const INSTANCE_KEY = 'photo-analyses-per-day:instance';

    /**
     * Refuses the person who has used up their analyses of the day, or when the instance has run as many as it may, without counting
     * anything.
     *
     * @throws ThrottleRequestsException When the person has asked for as many analyses as they may today.
     * @throws InstanceQuotaReachedException When the instance has run as many analyses as it may today.
     */
    public function ensureAvailable(User $user): void
    {
        $key = $this->key($user->getKey());

        if (RateLimiter::tooManyAttempts($key, $this->limit())) {
            throw $this->refusal($key);
        }

        if ($this->instanceLimit() > 0 && RateLimiter::tooManyAttempts(self::INSTANCE_KEY, $this->instanceLimit())) {
            throw new InstanceQuotaReachedException($this->instanceLimit(), RateLimiter::availableIn(self::INSTANCE_KEY));
        }
    }

    /**
     * Reserves one analysis for the person and for the instance, and refuses it when either has asked for as many as it may today. Each
     * count and its check are one atomic step of the rate limiter.
     *
     * @throws ThrottleRequestsException When the person has used up their analyses of the day.
     * @throws InstanceQuotaReachedException When the instance has run as many analyses as it may today.
     */
    public function reserve(User $user): void
    {
        $key = $this->key($user->getKey());

        if (RateLimiter::hit($key, self::WINDOW) > $this->limit()) {
            throw $this->refusal($key);
        }

        if ($this->instanceLimit() > 0 && RateLimiter::hit(self::INSTANCE_KEY, self::WINDOW) > $this->instanceLimit()) {
            // Nothing is done for this person: their place goes back.
            RateLimiter::decrement($key, self::WINDOW);

            throw new InstanceQuotaReachedException($this->instanceLimit(), RateLimiter::availableIn(self::INSTANCE_KEY));
        }
    }

    /**
     * Gives back the places an analysis reserved for the person and for the instance, because it never reached Anthropic. Nothing goes
     * below zero: a window that ended since the reservation has nothing left to give back.
     *
     * @param  int  $userId  The person the analysis was reserved for.
     */
    public function release(int $userId): void
    {
        $this->giveBack($this->key($userId));

        if ($this->instanceLimit() > 0) {
            $this->giveBack(self::INSTANCE_KEY);
        }
    }

    /**
     * Takes one off a count, if it has one.
     */
    private function giveBack(string $key): void
    {
        if (RateLimiter::attempts($key) > 0) {
            RateLimiter::decrement($key, self::WINDOW);
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
     * The analyses the whole instance may run in a day; none is no cap.
     */
    private function instanceLimit(): int
    {
        return max(0, config()->integer('inventories.photo_analyses_per_day_instance'));
    }

    /**
     * The key of the count of a person.
     */
    private function key(int|string $userId): string
    {
        return 'photo-analyses-per-day:'.$userId;
    }
}
