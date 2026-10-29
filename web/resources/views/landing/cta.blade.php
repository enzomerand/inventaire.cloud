{{--
    The last word of a sales page: one sentence, the same action as the first screen, and the mark built large (the cloud of barcode
    bars and the name). The sentence is not a heading (the page has its own); the mark is a picture. The field is the primary color
    and the text the Tabler token that is readable on it.
--}}
<section class="landing-cta">
    <div class="container-xl">
        <div class="landing-cta-text">
            <p class="landing-cta-title">{{ __('Start your first inventory.') }}</p>
            <div class="landing-actions">
                <x-landing.start class="btn btn-light btn-lg" />
                <span class="landing-cta-note">{{ __('No credit card needed.') }}</span>
            </div>
        </div>

        <div class="landing-mark" aria-hidden="true">
            <x-cloud-bars />
            <span class="landing-wordmark">{{ config('app.name') }}</span>
        </div>
    </div>
</section>
