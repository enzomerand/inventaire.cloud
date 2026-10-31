{{--
    The terms of sale of the Pro plan: public and drawn by the server, in one address per language like the pricing page. The French text
    (legal/terms-fr) prevails; every other language shows a translation for information (legal/terms-en). The text is a partial of
    plain HTML, because the same French partial is rendered in the PDF sent with the confirmation of a subscription: its style lives in
    landing.css (`.legal`), no framework class is used inside it.

    While the identity of the seller is incomplete the page is a working version: a banner says so, and the head asks not to be indexed
    (App\Support\Seo\PublicPageMeta::terms()).

    `$seller` is the details of the seller (App\Legal\Seller), `$plans` the App\Landing\PricingPlans, `$termsVersionDate` the date of
    the version (App\Legal\Terms) as a person reads it, `$draft` whether the page is a working version and `$partial` the view of the
    text.
--}}
@extends('layouts.app')

@section('title', $seo->title)

@section('bleed')
    <div class="landing">
        <section class="landing-section landing-section-intro pb-4" aria-labelledby="terms-title">
            <div class="container-xl">
                <h1 id="terms-title" class="landing-h2 landing-h1">{{ __('Terms of sale') }}</h1>
                <p class="landing-fineprint mb-0">{{ __('Version of :date', ['date' => $termsVersionDate]) }}</p>
            </div>
        </section>

        <section class="landing-section-flush pt-0 pb-5">
            <div class="container-xl">
                @if ($draft)
                    <div class="alert alert-warning text-body" role="status">{{ __('Working version: information to be completed') }}</div>
                @endif

                <article class="legal">
                    @include($partial)
                </article>
            </div>
        </section>
    </div>
@endsection
