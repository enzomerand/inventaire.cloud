{{--
    HTML body of the sign-in code mail. Self-contained on purpose: inline styles only, no image, font or script,
    so nothing is fetched from a third party when the mail is opened.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Your :app sign-in code: :code', ['app' => config('app.name'), 'code' => $code]) }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d273b;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border:1px solid #dce1e7;border-radius:6px;padding:32px;">
        <p style="margin:0 0 16px;font-size:16px;">{{ __('Your sign-in code is:') }}</p>
        <p style="margin:0 0 24px;font-size:32px;font-weight:700;letter-spacing:6px;font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;">{{ $code }}</p>
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('Enter it on the sign-in page of :app. It is valid for :minutes minutes and works once.', ['app' => config('app.name'), 'minutes' => $lifetimeMinutes]) }}</p>
        <p style="margin:0;font-size:13px;line-height:1.5;color:#667382;">{{ __('If you did not ask for this code, you can ignore this message.') }}</p>
    </div>
</body>
</html>
