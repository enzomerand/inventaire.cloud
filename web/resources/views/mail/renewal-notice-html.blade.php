{{-- HTML body of the notice of tacit renewal of a yearly subscription: the last date to refuse the renewal is in a boxed line. --}}
@extends('mail.contract-layout')

@section('title', __('Your yearly :app Pro subscription renews on :date', ['app' => config('app.name'), 'date' => $deadlineLabel]))

@section('content')
    <p style="margin:0 0 16px;font-size:16px;">{{ __('Hello :name,', ['name' => $name]) }}</p>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('Your yearly Pro subscription to :app for the workspace :workspace renews automatically for one year when the period that is paid for ends.', ['app' => config('app.name'), 'workspace' => $workspaceLabel]) }}</p>
    <div style="margin:0 0 16px;padding:12px 16px;border:2px solid #1d273b;border-radius:6px;font-size:15px;line-height:1.5;font-weight:600;">{{ __('Last date to refuse the renewal: :date', ['date' => $deadlineLabel]) }}</div>
    @if ($priceLabel !== '')
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('If you do nothing, the renewal is charged :price.', ['price' => $priceLabel]) }}</p>
    @endif
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('You can refuse the renewal, free of charge, until that date: cancel your contract from the subscription page. It then ends on that date and the workspace goes back to the Free plan; nothing is deleted.') }}</p>
    <p style="margin:0 0 16px;"><a href="{{ $billingUrl }}" style="display:inline-block;padding:10px 18px;background:#206bc4;border-radius:4px;color:#ffffff;font-size:14px;text-decoration:none;">{{ __('Cancel your contract') }}</a></p>
    <p style="margin:0;font-size:12px;line-height:1.5;color:#5b6578;word-break:break-all;">{{ $billingUrl }}</p>
@endsection
