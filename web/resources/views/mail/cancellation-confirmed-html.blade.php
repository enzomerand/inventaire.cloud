{{-- HTML body of the receipt of a cancellation: the date the contract ends and the effects. --}}
@extends('mail.contract-layout')

@section('title', __('Your :app Pro subscription is cancelled', ['app' => config('app.name')]))

@section('content')
    <p style="margin:0 0 16px;font-size:16px;">{{ __('Hello :name,', ['name' => $name]) }}</p>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('We received the cancellation of your Pro subscription to :app.', ['app' => config('app.name')]) }}</p>
    <ul style="margin:0 0 16px;padding:0 0 0 20px;font-size:14px;line-height:1.6;">
        <li>{{ __('Plan: Pro, :period', ['period' => $periodLabel]) }}</li>
        <li>{{ __('Workspace: :value', ['value' => $workspaceLabel]) }}</li>
        <li><strong>{{ __('Your contract ends on: :value', ['value' => $endsLabel]) }}</strong></li>
    </ul>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('Pro stays available until then and you will not be charged again. The period already paid is not refunded, unless the law provides otherwise.') }}</p>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('On that date the workspace goes back to the Free plan. Nothing is deleted: what goes beyond the limits of the Free plan stays, and creating more is refused until the workspace is back within them. You can export your data at any time.') }}</p>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('Changed your mind? You can keep your subscription from the subscription management until that date:') }}</p>
    <p style="margin:0;font-size:14px;line-height:1.5;"><a href="{{ $billingUrl }}" style="color:#206bc4;word-break:break-all;">{{ $billingUrl }}</a></p>
@endsection
