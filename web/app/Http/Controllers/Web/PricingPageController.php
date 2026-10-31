<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AbortUnlessPlansShown;
use App\Http\Middleware\UseUrlLocale;
use App\Landing\Faq;
use App\Landing\PricingPlans;
use App\Support\PublicUrls;
use App\Support\Seo\PublicPageMeta;
use Illuminate\Contracts\View\View;

/**
 * `/pricing` (and `/{locale}/pricing`): the Free and Pro plans side by side, public and rendered by the server.
 *
 * The page exists only on an instance that sells a plan or previews its plans ({@see PricingPlans::shown()}): anywhere else it is a 404, as
 * if it were not there (see {@see AbortUnlessPlansShown}). On a preview the Pro button cannot be used yet. The language is the one of the
 * address (see {@see UseUrlLocale}).
 */
final class PricingPageController extends Controller
{
    /**
     * Gets what the head of the page says to search engines.
     */
    public function __construct(private readonly PublicPageMeta $meta) {}

    /**
     * Shows the plans, their comparison and the questions about billing.
     */
    public function __invoke(): View
    {
        $plans = PricingPlans::current();

        return view('pricing', [
            'seo' => $this->meta->pricing($plans, app()->getLocale()),
            'plans' => $plans,
            'faq' => Faq::billing($plans),
            'localeAlternates' => PublicUrls::alternates(PublicUrls::pricingPath(...)),
        ]);
    }
}
