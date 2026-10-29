{{--
    Open source and self-hosting. A dark field on any theme: `data-bs-theme="dark"` makes Tabler flip its tokens inside it, and the
    background and the text take them. Only facts the repository can show: the licence, the Docker image and the wizard, the three
    database engines, no tracker, templates as JSON files.

    Icons: never pass a class attribute to <x-tabler-*>.
--}}
@php($repository = rtrim(config()->string('app.repository_url'), '/'))

<section id="open-source" class="landing-field landing-field-dark" data-bs-theme="dark" aria-labelledby="open-source-title">
    <div class="container-xl">
        <div class="row g-5 align-items-center">
            <div class="col-lg-6">
                <h2 id="open-source-title" class="landing-h2">{{ __('Open source, and yours to host') }}</h2>
                <p class="landing-text">{{ __('inventaire.cloud is released under the AGPL-3.0. Run the same app on your own server, read the code, report a bug or add a template.') }}</p>
                <div class="landing-actions">
                    <a class="btn btn-primary btn-lg" href="{{ $repository }}" target="_blank" rel="noopener">{{ __('Source code') }}</a>
                    <a class="landing-link" href="{{ \App\Support\PublicUrls::apiDocsPath() }}">{{ __('API documentation') }}</a>
                </div>
            </div>

            <div class="col-lg-6">
                <ul class="landing-facts-list list-unstyled">
                    @foreach ([__('AGPL-3.0 licence'), __('Docker image and setup wizard'), __('PostgreSQL, MySQL or MariaDB'), __('No tracker, no third-party CDN or font'), __('Templates are JSON files: send yours')] as $line)
                        <li>
                            <span class="landing-bullet" aria-hidden="true"><x-tabler-barcode aria-hidden="true" /></span>
                            {{ $line }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</section>
