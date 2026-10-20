{{--
    The layout of the public page of a share link (resources/views/share/show.blade.php): an inventory read-only, for anybody who holds
    the secret address.

    It has no session behind it, so it never calls csrf_token(), old(), @auth or anything that starts one, and it loads the stylesheet only:
    there is no script to run, the page is plain HTML (a table, a search form that is a GET and links). It has no navigation of the
    application and no language switcher (the choice would be stored in a cookie): the language is the one of the browser, or of the cookie
    the visitor may already have. Robots are asked not to index it nor to follow its links: the address is a secret, and the page carries
    no description, no canonical address and no social card, which would write that address into the head.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <x-seo :page="null" />

    <title>@yield('title') · {{ config('app.name') }}</title>

    @vite(['resources/css/app.css'])
</head>
<body>
    <a class="skip-link visually-hidden-focusable" href="#main-content">{{ __('Skip to content') }}</a>

    <div class="page">
        <header class="navbar navbar-expand-md d-print-none">
            <div class="container-xl">
                <a class="navbar-brand navbar-brand-autodark fw-bold" href="{{ route('home', absolute: false) }}">{{ config('app.name') }}</a>
            </div>
        </header>

        <div class="page-wrapper">
            <main class="page-body" id="main-content" tabindex="-1">
                <div class="container-xl">
                    @yield('content')
                </div>
            </main>

            <footer class="footer footer-transparent d-print-none">
                <div class="container-xl text-center">
                    <span class="text-secondary">{{ __('Read-only share made with :app', ['app' => config('app.name')]) }}</span>
                </div>
            </footer>
        </div>
    </div>
</body>
</html>
