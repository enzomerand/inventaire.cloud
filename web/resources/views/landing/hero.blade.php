{{--
    The first screen of the home page: what the application is, and one action. The motif (the cloud of barcode bars of the logo) is the
    picture: it bleeds off the right edge on a wide screen and sits under the buttons, cropped, on a phone. Nothing here depends on
    the plans except one sentence (see App\Landing\Pitch): `$plans` is null where the instance sells none.
--}}
<section class="landing-hero" aria-labelledby="landing-title">
    <div class="container-xl">
        <div class="landing-hero-text">
            <h1 id="landing-title" class="landing-display">{{ __('Your inventory, in the cloud.') }}</h1>
            <p class="landing-lead">{{ \App\Landing\Pitch::lead($plans) }}</p>

            <div class="landing-actions">
                <x-landing.start class="btn btn-primary btn-lg" />
                <a class="landing-link" href="{{ \App\Support\PublicUrls::templatesPath(app()->getLocale()) }}">{{ __('Browse the templates') }}</a>
            </div>

            <ul class="list-inline list-inline-dots landing-facts">
                <li class="list-inline-item">{{ \App\Landing\Pitch::fieldTypes() }}</li>
                <li class="list-inline-item">XLSX, ODS, CSV, PDF</li>
                <li class="list-inline-item">{{ __('AGPL-3.0 open source') }}</li>
            </ul>
        </div>
    </div>

    <div class="landing-cloud">
        <x-cloud-bars mode="inline" />
    </div>
</section>
