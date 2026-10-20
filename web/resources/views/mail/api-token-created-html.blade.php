{{--
    HTML body of the API token notice. Self-contained on purpose: inline styles only, no image, font or script, so nothing
    is fetched from a third party when the mail is opened. The only link is the account page of the instance. Never the
    value of the token.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('New API token on your :app account', ['app' => config('app.name')]) }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d273b;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border:1px solid #dce1e7;border-radius:6px;padding:32px;">
        <p style="margin:0 0 16px;font-size:16px;">{{ __('Hello :name,', ['name' => $name]) }}</p>
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('An API token was created on your :app account:', ['app' => config('app.name')]) }}</p>
        <ul style="margin:0 0 24px;padding:0 0 0 20px;font-size:14px;line-height:1.6;">
            <li>{{ __('Token name: :value', ['value' => $tokenLabel]) }}</li>
            <li>{{ __('Permissions: :value', ['value' => $abilityLabels]) }}</li>
            <li>{{ __('Created: :value', ['value' => $createdLabel]) }}</li>
            <li>{{ __('Expires: :value', ['value' => $expiresLabel]) }}</li>
            <li>{{ __('IP address: :value', ['value' => $ipLabel]) }}</li>
            <li style="word-break:break-word;">{{ __('Browser or application: :value', ['value' => $agentLabel]) }}</li>
        </ul>
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('If you did not create this token, revoke it now from your account page and sign out everywhere:') }}</p>
        <p style="margin:0;font-size:14px;line-height:1.5;"><a href="{{ $accountUrl }}" style="color:#206bc4;word-break:break-all;">{{ $accountUrl }}</a></p>
    </div>
</body>
</html>
