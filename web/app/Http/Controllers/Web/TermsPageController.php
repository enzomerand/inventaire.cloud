<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AbortUnlessPlansShown;
use App\Http\Middleware\UseUrlLocale;
use App\Landing\PricingPlans;
use App\Legal\Seller;
use App\Legal\Terms;
use App\Support\PublicUrls;
use App\Support\Seo\PublicPageMeta;
use Illuminate\Contracts\View\View;

/**
 * `/terms` (and `/{locale}/terms`): the terms of sale of the Pro plan, public and rendered by the server.
 *
 * The page exists only where the pricing page does ({@see PricingPlans::shown()}, see {@see AbortUnlessPlansShown}): anywhere else it is a
 * 404. The French terms (`resources/views/legal/terms-fr.blade.php`) are the ones that prevail; every other language shows a translation
 * for information. While the seller is incomplete ({@see Seller::complete()}) the page is a working version: it says so and asks not to
 * be indexed. The language is the one of the address (see {@see UseUrlLocale}).
 */
final class TermsPageController extends Controller
{
    /**
     * Gets what the head of the page says to search engines.
     */
    public function __construct(private readonly PublicPageMeta $meta) {}

    /**
     * Shows the terms in the language of the address, with the seller, the plans they describe and the date of their version.
     */
    public function __invoke(): View
    {
        $locale = app()->getLocale();

        return view('terms', [
            'seo' => $this->meta->terms($locale),
            'seller' => Seller::details(),
            'plans' => PricingPlans::current(),
            'termsVersion' => Terms::VERSION,
            'termsVersionDate' => Terms::versionDate(),
            'draft' => ! Seller::complete(),
            'partial' => $locale === 'fr' ? 'legal.terms-fr' : 'legal.terms-en',
            'localeAlternates' => PublicUrls::alternates(PublicUrls::termsPath(...)),
        ]);
    }
}
