<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Landing\PricingPlans;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides the terms of sale on an instance that has nothing to say about plans and no contract running ({@see PricingPlans::termsAvailable()}).
 *
 * The terms are a page of the pricing family, with one exception: the mails of a contract (the confirmation, the notice of renewal) link
 * to them, and a customer must still be able to read what they signed when the instance stopped selling or previewing, so they stay
 * where at least one subscription exists. Like {@see AbortUnlessPlansShown} it is a 404 of its own that comes before
 * {@see UseUrlLocale}.
 */
final class AbortUnlessTermsAvailable
{
    /**
     * Answers 404 when the instance shows no plan and holds no subscription.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(PricingPlans::termsAvailable(), 404);

        return $next($request);
    }
}
