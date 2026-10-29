{{--
    The pricing page: the Free and Pro plans side by side, their comparison line by line, and the questions about billing. Public and
    drawn by the server. It exists on an instance that sells a plan only (App\Http\Controllers\Web\PricingPageController answers 404
    elsewhere), in one address per language like the templates.

    `$plans` is the App\Landing\PricingPlans, `$faq` the billing questions (App\Landing\Faq).
--}}
@extends('layouts.app')

@section('title', $seo->title)

@section('bleed')
    <div class="landing">
        <x-cloud-bars mode="defs" />

        <section class="landing-section landing-section-intro" aria-labelledby="pricing-title">
            <div class="container-xl">
                <h1 id="pricing-title" class="landing-h2 landing-h1">{{ __('Free to start. Pro to grow.') }}</h1>
                <p class="landing-statement">{{ __('One plan per workspace: the owner subscribes, every member benefits. Cancel anytime.') }}</p>
            </div>
        </section>

        <section aria-labelledby="plans-title">
            <h2 id="plans-title" class="visually-hidden">{{ __('Plans') }}</h2>
            @include('partials.pricing-plans')
        </section>

        <section class="landing-section" aria-labelledby="compare-title">
            <div class="container-xl">
                <h2 id="compare-title" class="landing-h2">{{ __('Compare the plans') }}</h2>
                @include('partials.pricing-table')
            </div>
        </section>

        @include('landing.faq', ['faqId' => 'billing-faq', 'faqTitle' => __('Billing questions')])
        @include('landing.cta')
    </div>
@endsection
