{{--
    HTML body of the Stripe risk alert. Self-contained on purpose: inline styles only, no image, font or script, so nothing is
    fetched from a third party when the mail is opened. The only link is the Stripe dashboard. Nothing about the customer.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Stripe alert on :app: :event', ['app' => config('app.name'), 'event' => $eventLabel]) }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d273b;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border:1px solid #dce1e7;border-radius:6px;padding:32px;">
        <p style="margin:0 0 16px;font-size:16px;">{{ __('Hello,') }}</p>
        @if ($problem)
            <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('Something about the payments needs your attention on :app:', ['app' => config('app.name')]) }}</p>
            <p style="margin:0 0 8px;font-size:14px;line-height:1.5;font-weight:600;">{{ $eventLabel }}</p>
            <p style="margin:0 0 8px;font-size:14px;line-height:1.5;">{{ $explanation }}</p>
            <ul style="margin:0 0 24px;padding:0 0 0 20px;font-size:14px;line-height:1.6;">
                @if ($objectId !== null)
                    <li>{{ __('Stripe customer: :value', ['value' => $objectId]) }}</li>
                @endif
                @if ($workspaceUlid !== null)
                    <li>{{ __('Workspace: :value', ['value' => $workspaceUlid]) }}</li>
                @endif
            </ul>
        @else
            <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('Stripe reported something that needs your attention on :app:', ['app' => config('app.name')]) }}</p>
            <p style="margin:0 0 8px;font-size:14px;line-height:1.5;font-weight:600;">{{ $eventLabel }}</p>
            <ul style="margin:0 0 24px;padding:0 0 0 20px;font-size:14px;line-height:1.6;">
                <li>{{ __('Stripe event: :value', ['value' => $eventType]) }}</li>
                @if ($objectId !== null)
                    <li>{{ __('Stripe reference: :value', ['value' => $objectId]) }}</li>
                @endif
                @if ($amountLabel !== null)
                    <li>{{ __('Amount: :value', ['value' => $amountLabel]) }}</li>
                @endif
            </ul>
        @endif
        @unless ($livemode)
            <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('This alert comes from the test mode of Stripe.') }}</p>
        @endunless
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('Look at it in the Stripe dashboard:') }}</p>
        <p style="margin:0;font-size:14px;line-height:1.5;"><a href="{{ $dashboardUrl }}" style="color:#206bc4;word-break:break-all;">{{ $dashboardUrl }}</a></p>
    </div>
</body>
</html>
