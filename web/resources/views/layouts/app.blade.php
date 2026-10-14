<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="csrf-refresh-url" content="{{ route('csrf.refresh', absolute: false) }}">
    {{-- The HTTP helper sends signed-out users here on a 401 instead of reloading: a page that calls the API as a
         visitor must pass `silent: true` and handle the error itself. --}}
    <meta name="login-url" content="{{ route('login', absolute: false) }}">

    <title>@hasSection('title')@yield('title') · @endif{{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link visually-hidden-focusable" href="#main-content">{{ __('Skip to content') }}</a>

    <div class="page">
        <header class="navbar navbar-expand-md d-print-none">
            <div class="container-xl">
                <a class="navbar-brand navbar-brand-autodark fw-bold" href="{{ route('home') }}">
                    {{ config('app.name') }}
                </a>

                <div class="navbar-nav flex-row order-md-last align-items-center gap-2">
                    <x-locale-switcher />

                    {{-- The install wizard runs before there are any accounts, or even a database to look them up in. --}}
                    @unless (request()->routeIs('install.*'))
                        @auth
                            <x-account-menu />
                        @else
                            @unless (request()->routeIs('login'))
                                <a class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1" href="{{ route('login') }}">
                                    <x-tabler-login aria-hidden="true" />
                                    {{ __('Sign in') }}
                                </a>
                            @endunless
                        @endauth
                    @endunless
                </div>
            </div>
        </header>

        <div class="page-wrapper">
            @hasSection('heading')
                <div class="page-header d-print-none">
                    <div class="container-xl">
                        <h1 class="page-title">@yield('heading')</h1>
                    </div>
                </div>
            @endif

            <main class="page-body" id="main-content" tabindex="-1">
                <div class="container-xl">
                    @yield('content')
                </div>
            </main>

            @php($repository = rtrim(config()->string('app.repository_url'), '/'))

            <footer class="footer footer-transparent d-print-none">
                <div class="container-xl">
                    <div class="row text-center align-items-center flex-row-reverse">
                        <div class="col-lg-auto ms-lg-auto">
                            <ul class="list-inline list-inline-dots mb-0">
                                <li class="list-inline-item">
                                    <a class="link-secondary d-inline-flex align-items-center gap-1" href="{{ $repository }}" target="_blank" rel="noopener">
                                        <x-tabler-code aria-hidden="true" />
                                        {{ __('Source code') }}
                                    </a>
                                </li>
                                <li class="list-inline-item">
                                    <a class="link-secondary" href="{{ $repository }}/blob/HEAD/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a>
                                </li>
                            </ul>
                        </div>
                        <div class="col-12 col-lg-auto mt-3 mt-lg-0">
                            <span class="text-secondary">&copy; {{ date('Y') }} {{ config('app.name') }}</span>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <x-toasts />
    <x-client-messages />
</body>
</html>
