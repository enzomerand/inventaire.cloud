{{--
    What the application does, in one breath and then in detail. The row of chips under the statement is a picture of the kinds of
    value a field holds (a quantity, a rating, a barcode, a file): it is decoration, hidden from assistive technologies.

    Icons: never pass a class attribute to <x-tabler-*>; the bullet is sized by .landing-bullet.
--}}
@php
    $features = [
        ['title' => __('Typed fields'), 'text' => __('Money, dates, ratings, tags, barcodes, files, photos: each value is checked, sorted and filtered for what it is.')],
        ['title' => __('A fast grid'), 'text' => __('Edit in place, search, sort, filter, total a column, switch to a gallery.')],
        ['title' => __('Scan and install'), 'text' => __('Install it like an app and scan barcodes with the camera.')],
        ['title' => __('History and files'), 'text' => __('Every change is kept. Attach photos, invoices and manuals to each item.')],
        ['title' => __('Teams and sharing'), 'text' => __('Owners, editors and viewers, and read-only links for everyone else.')],
        ['title' => __('Open API'), 'text' => __('A documented JSON API with personal tokens for your own tools.'), 'link' => \App\Support\PublicUrls::apiDocsPath()],
    ];
@endphp

<section id="features" class="landing-section" aria-labelledby="features-title">
    <div class="container-xl">
        <h2 id="features-title" class="landing-h2">{{ __('What an inventory needs') }}</h2>
        <p class="landing-statement">{{ __('Typed fields, from money to ratings. Scan a barcode with your phone. Import your spreadsheet, export it as XLSX or PDF. Share a read-only link.') }}</p>

        <ul class="landing-chips list-unstyled" aria-hidden="true" data-ui>
            <li class="landing-chip">&times;&thinsp;6</li>
            <li class="landing-chip app-stars">&starf;&starf;&starf;&starf;&star;</li>
            <li class="landing-chip"><x-tabler-barcode aria-hidden="true" /> <span class="font-monospace">3760123456789</span></li>
            <li class="landing-chip"><x-tabler-file-type-pdf aria-hidden="true" /> PDF</li>
        </ul>

        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-5 landing-features">
            @foreach ($features as $feature)
                <div class="col">
                    <h3 class="h4 landing-feature-title">
                        <span class="landing-bullet" aria-hidden="true"><x-tabler-barcode aria-hidden="true" /></span>
                        {{ $feature['title'] }}
                    </h3>
                    <p class="landing-text">
                        {{ $feature['text'] }}
                        @isset($feature['link'])
                            <a href="{{ $feature['link'] }}">{{ __('API documentation') }}</a>
                        @endisset
                    </p>
                </div>
            @endforeach
        </div>
    </div>
</section>
