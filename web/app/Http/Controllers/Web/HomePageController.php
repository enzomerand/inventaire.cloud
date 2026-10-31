<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Landing\Faq;
use App\Landing\PricingPlans;
use App\Landing\UseCases;
use App\Support\Seo\PublicPageMeta;
use Illuminate\Contracts\View\View;

/**
 * `/`: the home page, which is the sales page, public and rendered by the server.
 *
 * It exists once, in the language of the request. It talks about plans and prices only on an instance that sells a plan or previews its
 * plans ({@see PricingPlans::shown()}); everywhere else it presents the application as free and open source.
 */
final class HomePageController extends Controller
{
    /**
     * Gets the use cases the page lets a visitor pick from, and what the head of the page says to search engines.
     */
    public function __construct(
        private readonly UseCases $useCases,
        private readonly PublicPageMeta $meta,
    ) {}

    /**
     * Shows the home page.
     */
    public function __invoke(): View
    {
        $locale = app()->getLocale();
        $plans = PricingPlans::shown() ? PricingPlans::current() : null;
        $faq = Faq::home($plans);

        return view('home', [
            'seo' => $this->meta->home($locale, $plans, $faq),
            'plans' => $plans,
            'faq' => $faq,
            'useCases' => $this->useCases->panels($locale),
        ]);
    }
}
