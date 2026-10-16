<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Seo\PublicPageMeta;
use Illuminate\Contracts\View\View;

/**
 * `/`: the home page, public.
 */
final class HomePageController extends Controller
{
    /**
     * Shows the home page, with what search engines read about it.
     */
    public function __invoke(PublicPageMeta $meta): View
    {
        return view('home', ['seo' => $meta->home(app()->getLocale())]);
    }
}
