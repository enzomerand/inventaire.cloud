{{--
    HTML body of the welcome mail. Self-contained on purpose: inline styles only, no image, font or script, so nothing
    is fetched from a third party when the mail is opened. The only link is the sign-in page of the instance.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Your :app account is ready', ['app' => config('app.name')]) }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d273b;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border:1px solid #dce1e7;border-radius:6px;padding:32px;">
        <p style="margin:0 0 16px;font-size:16px;">{{ __('Hello :name,', ['name' => $name]) }}</p>
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('An administrator created an account for you on :app, with this email address.', ['app' => config('app.name')]) }}</p>
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('There is no password: open the page below, enter this email address and type the 6-digit code we email you.') }}</p>
        <p style="margin:0 0 24px;font-size:14px;line-height:1.5;"><a href="{{ $loginUrl }}" style="color:#206bc4;word-break:break-all;">{{ $loginUrl }}</a></p>
        <p style="margin:0;font-size:13px;line-height:1.5;color:#667382;">{{ __('If you were not expecting this message, you can ignore it.') }}</p>
    </div>
</body>
</html>
