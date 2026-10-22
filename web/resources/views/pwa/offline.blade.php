{{--
    The page the service worker shows when the network is down (OfflineController): resources/js/sw/service-worker.js keeps a copy of it
    when it installs, and shows that copy in place of the browser's error page.

    It is made to be copied. It is the same for everybody (no user, no CSRF token, nothing that starts a session), and it needs nothing else
    from the server: the style and the icon are inline, there is no script and no font. "Try again" is a link to the empty address, which is the
    address of the page the visitor asked for (the page keeps the address of the navigation it answers), so it asks for that page again.
    Its source is part of the version of the worker (ServiceWorkerScript): editing it replaces the copy the browsers keep.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="{{ App\Pwa\WebManifest::THEME_COLOR }}">
    <meta name="color-scheme" content="light dark">

    <title>{{ __('You are offline') }} · {{ config('app.name') }}</title>

    <style>
        :root {
            --page: {{ App\Pwa\WebManifest::BACKGROUND_COLOR }};
            --text: #1d273b;
            --muted: #5f6b7e;
            --accent: {{ App\Pwa\WebManifest::THEME_COLOR }};
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --page: #151f2c;
                --text: #f1f5f9;
                --muted: #a3afc0;
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: 1.5rem max(1.5rem, env(safe-area-inset-right)) 1.5rem max(1.5rem, env(safe-area-inset-left));
            background: var(--page);
            color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.5;
        }

        main {
            width: 100%;
            max-width: 24rem;
            text-align: center;
        }

        svg {
            display: block;
            width: 6rem;
            height: 6rem;
            margin: 0 auto 1.5rem;
        }

        h1 {
            margin: 0 0 0.5rem;
            font-size: 1.5rem;
            line-height: 1.3;
        }

        p {
            margin: 0 0 1.5rem;
            color: var(--muted);
        }

        a {
            display: inline-block;
            min-width: 9rem;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            background: var(--accent);
            color: #fff;
            font-weight: 600;
            text-decoration: none;
        }

        a:focus-visible {
            outline: 3px solid var(--text);
            outline-offset: 3px;
        }
    </style>
</head>
<body>
    <main>
        <svg viewBox="0 0 512 512" aria-hidden="true" focusable="false">
            <rect width="512" height="512" rx="112" fill="{{ App\Pwa\WebManifest::THEME_COLOR }}"/>
            <g fill="#fff">
                <rect x="112" y="140" width="18" height="232"/>
                <rect x="139" y="140" width="9" height="232"/>
                <rect x="166" y="140" width="27" height="232"/>
                <rect x="202" y="140" width="9" height="232"/>
                <rect x="229" y="140" width="18" height="232"/>
                <rect x="256" y="140" width="27" height="232"/>
                <rect x="292" y="140" width="9" height="232"/>
                <rect x="319" y="140" width="18" height="232"/>
                <rect x="346" y="140" width="9" height="232"/>
                <rect x="373" y="140" width="27" height="232"/>
            </g>
        </svg>

        <h1>{{ __('You are offline') }}</h1>
        <p>{{ __('Check your connection, then try again.') }}</p>
        <a href="">{{ __('Try again') }}</a>
    </main>
</body>
</html>
