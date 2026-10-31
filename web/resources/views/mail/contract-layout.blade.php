{{--
    The frame of the HTML mails about a contract (confirmation, cancellation receipt, renewal notice, withdrawal acknowledgement).
    Self-contained on purpose: inline styles only, no image, font or script, so nothing is fetched from a third party when the mail is
    opened. The views that extend it give the `title` and the `content`.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d273b;">
    <div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #dce1e7;border-radius:6px;padding:32px;">
        @yield('content')
    </div>
</body>
</html>
