{{--
    The documentation of the public API: public, drawn by the server (search engines read it) from the OpenAPI document, with no
    script of its own and nothing loaded from another host. The introduction, then every operation under its tag, then the
    schemas; a column of links follows the reader on a wide screen and folds into a button on a narrow one (Bootstrap's collapse,
    driven by its data attributes: without script the links are simply not shown and the page is read in order).

    The text of the document is English whatever the language of the page: the elements that hold it say `lang="en"`. The
    labels around it are translated. See App\Docs\ApiReference for what `$reference` holds.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', $seo->title)

@section('heading', __('API documentation'))

@section('content')
    <div class="row row-cards">
        <div class="col-lg-3">
            <button class="btn btn-outline-secondary w-100 d-inline-flex align-items-center justify-content-center gap-1 d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#api-docs-contents" aria-expanded="false" aria-controls="api-docs-contents">
                <x-tabler-list aria-hidden="true" />
                {{ __('Contents') }}
            </button>

            <nav class="collapse d-lg-block app-docs-nav mt-3 mt-lg-0" id="api-docs-contents" aria-label="{{ __('Contents') }}">
                <div class="fw-bold mb-1">{{ __('Introduction') }}</div>
                <ul class="list-unstyled mb-3">
                    @foreach ($reference['introHeadings'] as $heading)
                        <li><a class="d-block py-1 text-break" href="#{{ $heading['id'] }}" lang="en">{{ $heading['text'] }}</a></li>
                    @endforeach
                </ul>

                @foreach ($reference['tags'] as $tag)
                    <div class="fw-bold mb-1"><a class="text-reset" href="#{{ $tag['id'] }}" lang="en">{{ $tag['name'] }}</a></div>
                    <ul class="list-unstyled mb-3">
                        @foreach ($tag['operations'] as $op)
                            <li><a class="d-block py-1 text-break" href="#{{ $op['id'] }}" lang="en">{{ $op['summary'] }}</a></li>
                        @endforeach
                    </ul>
                @endforeach

                <div class="fw-bold mb-1"><a class="text-reset" href="#schemas">{{ __('Schemas') }}</a></div>
            </nav>
        </div>

        <div class="col-lg-9 app-docs-content">
            <section class="card mb-4" aria-labelledby="api-docs-start">
                <div class="card-body">
                    <h2 class="h3" id="api-docs-start">{{ __('Getting started') }}</h2>

                    <dl class="row mb-3">
                        <dt class="col-sm-3">{{ __('Base URL') }}</dt>
                        <dd class="col-sm-9"><code class="text-break">{{ $reference['baseUrl'] }}</code></dd>
                        <dt class="col-sm-3">{{ __('Version') }}</dt>
                        <dd class="col-sm-9 mb-0">{{ $reference['version'] }}</dd>
                    </dl>

                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-primary d-inline-flex align-items-center gap-1" href="{{ route('api.v1.openapi', absolute: false) }}">
                            <x-tabler-download aria-hidden="true" />
                            {{ __('Download the OpenAPI specification') }}
                        </a>
                        <a class="btn btn-outline-primary d-inline-flex align-items-center gap-1" href="{{ route('app.account', absolute: false) }}" rel="nofollow">
                            <x-tabler-key aria-hidden="true" />
                            {{ __('Create an API token') }}
                        </a>
                    </div>
                    <p class="text-secondary small mt-2 mb-0">{{ __('API tokens are made from the page of your account, in the API tokens card.') }}</p>
                </div>
            </section>

            <section class="card mb-4" aria-label="{{ __('Introduction') }}">
                <div class="card-body markdown" lang="en">{{ $reference['intro'] }}</div>
            </section>

            @foreach ($reference['tags'] as $tag)
                <section class="mb-5" aria-labelledby="{{ $tag['id'] }}">
                    <h2 class="h2 mb-1" id="{{ $tag['id'] }}" lang="en">{{ $tag['name'] }}</h2>
                    <div class="text-secondary mb-3" lang="en">{{ $tag['description'] }}</div>

                    @foreach ($tag['operations'] as $op)
                        @include('docs.operation', ['op' => $op])
                    @endforeach
                </section>
            @endforeach

            <section class="mb-5" aria-labelledby="schemas">
                <h2 class="h2 mb-1" id="schemas">{{ __('Schemas') }}</h2>
                <p class="text-secondary mb-3">{{ __('The shapes the operations above send and answer with.') }}</p>

                @foreach ($reference['schemas'] as $schema)
                    @include('docs.schema', ['schema' => $schema])
                @endforeach
            </section>
        </div>
    </div>
@endsection
