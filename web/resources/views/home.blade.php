{{--
    The home page, which is the sales page: public, drawn by the server (search engines read it), edge to edge (the `bleed` section of the
    layout: each part draws its own container). It reads as one sentence in order: what it is (hero), what it does (features), what
    it looks like for a use case (picker), how it goes (steps), what it costs (only where the instance sells a plan), why trust it
    (open source), the questions, and the same action again.

    `$plans` is the App\Landing\PricingPlans, or null on an instance that sells nothing: nothing about plans, prices or payment is
    then in the page, in the markup, the structured data or the head. `$useCases` are the templates to pick from (App\Landing\UseCases),
    `$faq` the questions (App\Landing\Faq).
--}}
@extends('layouts.app')

@section('title', $seo->title)

@section('bleed')
    <div class="landing">
        {{-- The symbol the pictures refer to: defined once, before the first one. --}}
        <x-cloud-bars mode="defs" />

        @include('landing.hero')
        @include('landing.features')

        @if ($useCases !== [])
            @include('landing.use-cases')
            @include('landing.how')
        @endif

        @if ($plans !== null)
            <section id="pricing" class="landing-section landing-section-flush" aria-labelledby="pricing-title">
                <div class="container-xl">
                    <h2 id="pricing-title" class="landing-h2">{{ __('Free to start, Pro to grow') }}</h2>
                </div>

                @include('partials.pricing-plans')

                <div class="container-xl landing-more">
                    <a class="landing-link" href="{{ \App\Support\PublicUrls::pricingPath(app()->getLocale()) }}">{{ __('Compare the plans in detail') }}</a>
                </div>
            </section>
        @endif

        @include('landing.open-source')
        @include('landing.faq', ['faqId' => 'faq', 'faqTitle' => __('Questions')])
        @include('landing.cta')
    </div>
@endsection
