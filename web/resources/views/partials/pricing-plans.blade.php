{{--
    The two plans of an instance that sells one, as two flat fields side by side: Free, and Pro (the larger one, in the color of the
    brand). Everything shown comes from App\Landing\PricingPlans (the configuration of the instance): no amount, no limit is written here.

    The Pro field offers a switch between the monthly and the yearly price when the instance has a yearly Price. The switch is the
    Alpine component `pricingPeriod` (resources/js/components/pricing-period.js) on two native radios; both prices are in the page and
    the stylesheet shows the one of `data-period`. Without the script the switch stays hidden (`hidden`, until the component starts), the
    monthly price is shown and the yearly one follows as a line ("or ... a year"), and the button leads to the monthly plan.

    The button of Pro leads to /app/billing, which a visitor who is not signed in reaches through the sign-in page and comes back to. While
    the instance only previews its plans (`$plans->selling` is false: nothing can be bought yet) it is a disabled "Coming soon" button, with
    no link anywhere in the field, and nothing in the page speaks of Stripe or of cancelling. Every amount of Pro is VAT-included and says
    so ("TTC"), and the terms of sale are linked under the plans.
    The names of the plans are <h3>: the page that includes this partial has the <h2> before.

    `$plans` is the App\Landing\PricingPlans.
--}}
@php
    $yearly = $plans->year !== null;
    $proMonth = route('app.billing', ['interval' => 'month'], absolute: false);
    $proYear = route('app.billing', ['interval' => 'year'], absolute: false);
    $summary = fn (string $plan): string => __('Up to :inventories of :items each, :storage of files, :members, and :links per inventory.', [
        'inventories' => $plans->inventories($plan),
        'items' => $plans->items($plan),
        'storage' => \App\Support\FileSize::format($plans->limit($plan, 'storage_bytes')),
        'members' => $plans->members($plan),
        'links' => $plans->shareLinks($plan),
    ]);
@endphp

<div
    class="landing-plans"
    data-period="month"
    @if ($yearly)
        x-data="pricingPeriod"
        :data-period="period"
        :data-ready="ready"
        @if ($plans->selling)
            data-href-month="{{ $proMonth }}"
            data-href-year="{{ $proYear }}"
        @endif
    @endif
>
    <div class="row g-0">
        <div class="col-md-5">
            <article class="landing-plan landing-plan-free" aria-labelledby="plan-free">
                <div>
                    <h3 id="plan-free" class="landing-plan-name">{{ __('Free') }}</h3>
                    <p class="landing-plan-sentence">{{ __('For a home, a collection or a first try.') }}</p>
                    <p class="landing-plan-included">
                        {{ $summary('free') }}
                        @if ($plans->includesPhotoEntry('free'))
                            {{ __('Add items from photos with AI.') }}
                        @endif
                    </p>
                </div>

                <div class="landing-plan-foot">
                    <p class="landing-price"><span class="landing-price-amount">{{ $plans->price(0) }}</span></p>
                    <x-landing.start />
                </div>
            </article>
        </div>

        <div class="col-md-7">
            <article class="landing-plan landing-plan-pro" aria-labelledby="plan-pro">
                <div>
                    @if ($yearly)
                        <fieldset class="landing-toggle" hidden :hidden="!ready">
                            <legend class="visually-hidden float-none">{{ __('Billing period') }}</legend>
                            <input class="btn-check" type="radio" name="pricing-period" id="pricing-period-month" value="month" x-model="period" checked autocomplete="off">
                            <label class="landing-toggle-option" for="pricing-period-month">{{ __('Monthly') }}</label>
                            <input class="btn-check" type="radio" name="pricing-period" id="pricing-period-year" value="year" x-model="period" autocomplete="off">
                            <label class="landing-toggle-option" for="pricing-period-year">
                                {{ __('Yearly') }}
                                @if ($plans->saving() !== null)
                                    <span class="landing-toggle-saving">{{ __('Save :amount a year', ['amount' => $plans->saving()]) }}</span>
                                @endif
                            </label>
                        </fieldset>
                    @endif

                    <h3 id="plan-pro" class="landing-plan-name">{{ __('Pro') }}</h3>
                    <p class="landing-plan-sentence">{{ __('For shops, teams and large collections.') }}</p>
                    <p class="landing-plan-included">
                        {{ $summary('pro') }}
                        @if ($plans->includesPhotoEntry('pro'))
                            {{ __('Add items from photos with AI.') }}
                        @endif
                    </p>
                </div>

                <div class="landing-plan-foot">
                    <div class="landing-price" aria-live="polite">
                        <p class="landing-price-line landing-only-month">
                            <span class="landing-price-amount">{{ $plans->monthlyPrice() }}</span>
                            <span class="landing-price-period"><span class="landing-price-tax">{{ __('incl. VAT') }}</span> {{ __('/ month') }}</span>
                        </p>
                        @if ($yearly)
                            <p class="landing-price-line landing-only-year">
                                <span class="landing-price-amount">{{ $plans->yearlyPrice() }}</span>
                                <span class="landing-price-period"><span class="landing-price-tax">{{ __('incl. VAT') }}</span> {{ __('/ year') }}</span>
                            </p>
                            <p class="landing-price-note landing-only-year">{{ __('That is :price incl. VAT a month', ['price' => $plans->yearlyPricePerMonth()]) }}</p>
                            <p class="landing-price-note landing-price-alt">{{ __('or :price incl. VAT a year', ['price' => $plans->yearlyPrice()]) }}</p>
                        @endif
                    </div>

                    @if ($plans->selling)
                        <a class="btn btn-light btn-lg" href="{{ $proMonth }}" @if ($yearly) :href="ctaHref" @endif>{{ __('Choose Pro') }}</a>
                    @else
                        <button type="button" class="btn btn-light btn-lg" disabled>{{ __('Coming soon') }}</button>
                    @endif
                </div>
            </article>
        </div>
    </div>

    @if ($plans->selling)
        <p class="landing-fineprint container-xl">{{ __('Billed by Stripe. Cancel anytime.') }}</p>
    @endif
    <p class="landing-fineprint container-xl">
        {{ __('Prices include VAT.') }}
        <a class="landing-link" href="{{ \App\Support\PublicUrls::termsPath(app()->getLocale()) }}">{{ __('Terms of sale') }}</a>
    </p>
</div>
