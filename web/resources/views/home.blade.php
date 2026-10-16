@extends('layouts.app')

@section('content')
    <div class="card">
        <div class="card-body text-center py-5">
            <div class="hero-icon text-primary mb-3">
                <x-tabler-packages aria-hidden="true" />
            </div>
            <h1 class="display-6 mb-2">{{ __('Inventories, simple and free') }}</h1>
            <p class="lead text-secondary mb-0">
                {{ __('A free inventory tool for professionals and individuals.') }}
            </p>
            <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
                @auth
                    <a class="btn btn-primary" href="{{ route('app') }}">{{ __('Open my space') }}</a>
                @endauth
                <a class="btn @auth btn-outline-primary @else btn-primary @endauth" href="{{ \App\Support\PublicUrls::templatesPath(app()->getLocale()) }}">{{ __('Browse the templates') }}</a>
            </div>
        </div>
    </div>
@endsection
