<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">

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

                <div class="navbar-nav flex-row order-md-last">
                    <x-locale-switcher />
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

            <footer class="footer footer-transparent d-print-none">
                <div class="container-xl">
                    <div class="row text-center align-items-center flex-row-reverse">
                        <div class="col-lg-auto ms-lg-auto">
                            <ul class="list-inline list-inline-dots mb-0">
                                <li class="list-inline-item">
                                    <a class="link-secondary d-inline-flex align-items-center gap-1" href="{{ config('app.repository_url') }}" target="_blank" rel="noopener">
                                        <x-tabler-brand-github aria-hidden="true" />
                                        GitHub
                                    </a>
                                </li>
                                <li class="list-inline-item">
                                    <a class="link-secondary" href="{{ config('app.repository_url') }}/blob/main/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a>
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
