{{--
    The page of an error (a 404, a 403, a 419, a 500...): it replaces the one of the framework, which says <html lang="en"> whatever the
    language of the visitor and has no way back.

    It is drawn on the error path, where anything may be what failed, so it stands alone: the styles are inline (no @vite, no stylesheet to
    fetch), it never calls csrf_token(), old(), @auth or anything that reads the session, and its only link is the home page, by a path
    (no route() to resolve). The language is the one SetLocale resolved for the request (the browser, or the cookie), and the framework's
    own views extend this one, so each of them gets it: they fill `title`, `code` and `message`, and a view may add a `hint`.

    A page that is the same for every cause of an error, such as the 404 of a share link, must stay so: nothing here depends on the request.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">

    <title>@yield('title') · {{ config('app.name') }}</title>

    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">

    <style>
        :root { --bg: #fafafa; --text: #1d273b; --muted: #626976; --line: #dce1e7; --accent: #066fd1; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #151f2c; --text: #e6e9ee; --muted: #9ba3b0; --line: #2f3b4c; --accent: #5aa7f0; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.5; }
        main { width: 100%; max-width: 36rem; }
        .error-head { display: flex; align-items: center; }
        h1 { margin: 0; padding-right: 1rem; border-right: 1px solid var(--line); font-size: 1.25rem; font-weight: 600; letter-spacing: .02em; }
        .error-message { padding-left: 1rem; font-size: 1.25rem; overflow-wrap: anywhere; }
        .error-hint { margin: 1.5rem 0 0; color: var(--muted); overflow-wrap: anywhere; }
        .error-home { margin: 1.5rem 0 0; }
        a { color: var(--accent); }
        a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 2px; }
    </style>
</head>
<body>
    <main>
        <div class="error-head">
            <h1>@yield('code')</h1>
            <div class="error-message">@yield('message')</div>
        </div>

        @hasSection('hint')
            <p class="error-hint">@yield('hint')</p>
        @endif

        <p class="error-home"><a href="/">{{ __('Back to the home page') }}</a></p>
    </main>
</body>
</html>
