<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Landing\PricingPlans;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides the pages about plans on an instance that sells none ({@see PricingPlans::selling()}).
 *
 * A 404, as if the page were not there, and an answer of its own: it comes before {@see UseUrlLocale}, whose permanent redirect from the
 * prefixed address of the default language would otherwise lead a visitor to an address that is a 404 as well.
 */
final class AbortUnlessSelling
{
    /**
     * Answers 404 when the instance sells no plan.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(PricingPlans::selling(), 404);

        return $next($request);
    }
}
