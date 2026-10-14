{{--
    Install wizard page. Alpine runs in its CSP build: components are referenced
    by name and read their values from data-* attributes (see resources/js/app.js).
--}}
@extends('layouts.app')

@section('title', __('Installation'))
@section('heading', __('Installation'))

@section('content')
    @unless ($authorized)
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <form
                    class="card card-md"
                    x-data="installerToken"
                    data-url="{{ route('install.token', absolute: false) }}"
                    @submit.prevent="submit"
                    novalidate
                >
                    <div class="card-body">
                        <h2 class="card-title mb-3">{{ __('Setup token') }}</h2>
                        <p class="text-secondary">
                            {{ __('To prove you have access to this server, enter the setup token. It is written in storage/logs/installer.log and printed by this command:') }}
                        </p>
                        <pre class="p-2 rounded bg-body-secondary"><code>php artisan inventaire:install-token</code></pre>

                        <div class="mb-3">
                            <label class="form-label" for="setup-token">{{ __('Setup token') }}</label>
                            <input
                                id="setup-token"
                                type="text"
                                class="form-control"
                                :class="invalidClass"
                                x-model="token"
                                autocomplete="off"
                                autocapitalize="off"
                                spellcheck="false"
                            >
                            <div class="invalid-feedback" x-text="error"></div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100" :disabled="busy">
                            {{ __('Continue') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endunless

    @if ($authorized)
        @include('install.wizard')
    @endif
@endsection
