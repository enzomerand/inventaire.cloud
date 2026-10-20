{{--
    HTML body of the invitation to a workspace. Self-contained on purpose: inline styles only, no image, font or script, so nothing
    is fetched from a third party when the mail is opened. The only link is the page of invitations of the instance: the invitation
    is bound to the address of the invitee, so the link carries no secret.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('You have been invited to a workspace on :app', ['app' => config('app.name')]) }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d273b;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border:1px solid #dce1e7;border-radius:6px;padding:32px;">
        <p style="margin:0 0 16px;font-size:16px;">{{ __('Hello,') }}</p>
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;word-break:break-word;">{{ __(':inviter invited you to join the workspace ":workspace" on :app with the role :role.', ['inviter' => $inviter, 'workspace' => $workspace, 'app' => config('app.name'), 'role' => $roleLabel]) }}</p>
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('To answer, sign in with this email address and open the page of your invitations:') }}</p>
        <p style="margin:0 0 24px;font-size:14px;line-height:1.5;"><a href="{{ $pageUrl }}" style="color:#206bc4;word-break:break-all;">{{ $pageUrl }}</a></p>
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('The invitation is valid until :date.', ['date' => $expiresLabel]) }}</p>
        <p style="margin:0;font-size:13px;line-height:1.5;color:#626976;">{{ __('If you were not expecting this invitation, you can ignore this message: nothing happens unless you accept it.') }}</p>
    </div>
</body>
</html>
