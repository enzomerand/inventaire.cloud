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

    {{-- Robots and search engines: a public page passes `$seo` (see components/seo.blade.php); every other page asks not to be indexed. --}}
    <x-seo :page="$seo ?? null" />

    <title>@hasSection('title')@yield('title') · @endif{{ config('app.name') }}</title>

    {{-- The installable app: the manifest (see routes/web.php), the color of the browser around it, and the icons in the formats browsers look for. --}}
    <link rel="manifest" href="{{ route('pwa.manifest', absolute: false) }}">
    <meta name="theme-color" content="{{ App\Pwa\WebManifest::THEME_COLOR }}">
    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @php($selling = \App\Landing\PricingPlans::selling())

    <a class="skip-link visually-hidden-focusable" href="#main-content">{{ __('Skip to content') }}</a>

    <div class="page">
        <header class="navbar navbar-expand-md d-print-none">
            <div class="container-xl">
                <a class="navbar-brand navbar-brand-autodark fw-bold" href="{{ route('home') }}">
                    {{ config('app.name') }}
                </a>

                {{-- The links of the public pages (those that describe themselves with `$seo`): from the width where the header expands. The
                     pricing page exists only where the instance sells a plan. --}}
                @isset($seo)
                    <ul class="navbar-nav flex-row d-none d-md-flex me-auto gap-3 ms-3">
                        <li class="nav-item">
                            <a class="nav-link" href="{{ \App\Support\PublicUrls::templatesPath(app()->getLocale()) }}">{{ __('Templates') }}</a>
                        </li>
                        @if ($selling)
                            <li class="nav-item">
                                <a class="nav-link" href="{{ \App\Support\PublicUrls::pricingPath(app()->getLocale()) }}">{{ __('Pricing') }}</a>
                            </li>
                        @endif
                        <li class="nav-item">
                            <a class="nav-link" href="{{ rtrim(config()->string('app.repository_url'), '/') }}" target="_blank" rel="noopener">{{ __('Source code') }}</a>
                        </li>
                    </ul>
                @endisset

                <div class="navbar-nav flex-row order-md-last align-items-center gap-2">
                    {{-- A page that exists once per language, with an address each, links to its other languages. --}}
                    @isset($localeAlternates)
                        <x-locale-links :alternates="$localeAlternates" />
                    @else
                        <x-locale-switcher />
                    @endisset

                    {{-- The install wizard runs before there are any accounts, or even a database to look them up in. --}}
                    @unless (request()->routeIs('install.*'))
                        @auth
                            {{-- The invitations that wait for the person: on the pages of the app only, and only when there are some. --}}
                            <x-invitations-link />
                            <x-workspace-switcher :active="$activeWorkspace ?? null" />
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

            {{-- A page that sets the `bleed` section draws its own sections edge to edge (the home page and the pricing page); the others
                 sit in the container. --}}
            <main class="page-body @hasSection('bleed') landing-body @endif" id="main-content" tabindex="-1">
                @hasSection('bleed')
                    @yield('bleed')
                @else
                    <div class="container-xl">
                        @yield('content')
                    </div>
                @endif
            </main>

            @php($repository = rtrim(config()->string('app.repository_url'), '/'))

            <footer class="footer footer-transparent d-print-none">
                <div class="container-xl">
                    <div class="row text-center align-items-center flex-row-reverse">
                        <div class="col-lg-auto ms-lg-auto">
                            <ul class="list-inline list-inline-dots mb-0">
                                <li class="list-inline-item">
                                    <a class="link-secondary d-inline-flex align-items-center gap-1" href="{{ \App\Support\PublicUrls::templatesPath(app()->getLocale()) }}">
                                        <x-tabler-layout-grid aria-hidden="true" />
                                        {{ __('Inventory templates') }}
                                    </a>
                                </li>
                                @if ($selling)
                                    <li class="list-inline-item">
                                        <a class="link-secondary d-inline-flex align-items-center gap-1" href="{{ \App\Support\PublicUrls::pricingPath(app()->getLocale()) }}">
                                            <x-tabler-tag aria-hidden="true" />
                                            {{ __('Pricing') }}
                                        </a>
                                    </li>
                                @endif
                                <li class="list-inline-item">
                                    <a class="link-secondary d-inline-flex align-items-center gap-1" href="{{ \App\Support\PublicUrls::apiDocsPath() }}">
                                        <x-tabler-api aria-hidden="true" />
                                        {{ __('API') }}
                                    </a>
                                </li>
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
    <x-flash-notice />
    <x-client-messages />
</body>
</html>
