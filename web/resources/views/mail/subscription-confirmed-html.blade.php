{{-- HTML body of the confirmation of a subscription: the contract on a durable medium. The terms of sale are attached as a PDF. --}}
@extends('mail.contract-layout')

@section('title', __('Confirmation of your :app Pro subscription', ['app' => config('app.name')]))

@section('content')
    <p style="margin:0 0 16px;font-size:16px;">{{ __('Hello :name,', ['name' => $name]) }}</p>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('Your Pro subscription to :app is confirmed. This message is the confirmation of your contract, on a durable medium. The terms of sale, which end with the form of withdrawal, are attached (PDF, in French, the version that prevails).', ['app' => config('app.name')]) }}</p>
    <ul style="margin:0 0 16px;padding:0 0 0 20px;font-size:14px;line-height:1.6;">
        <li>{{ __('Plan: Pro, :period', ['period' => $periodLabel]) }}</li>
        <li>{{ __('Workspace: :value', ['value' => $workspaceLabel]) }}</li>
        @if ($priceLabel !== '')
            <li>{{ __('Price: :value', ['value' => $priceLabel]) }}</li>
        @endif
        @if ($startedLabel !== null)
            <li>{{ __('Started on: :value', ['value' => $startedLabel]) }}</li>
        @endif
        @if ($periodEndLabel !== null)
            <li>{{ __('Current period ends on: :value', ['value' => $periodEndLabel]) }}</li>
        @endif
    </ul>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('At your express request, the service started immediately, without waiting for the end of the withdrawal period. You acknowledged that you lose your right of withdrawal once the contract has been fully performed.') }}</p>
    @if ($withdrawalUntilLabel !== null)
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('You can withdraw from the contract, without giving a reason, until :moment: use the "Withdraw from the contract here" button on the subscription page, or send the withdrawal form attached to the terms.', ['moment' => $withdrawalUntilLabel]) }}</p>
    @endif
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('You can cancel your contract at any time, free of charge, with the "Cancel your contract" button on the subscription page:') }}</p>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;"><a href="{{ $billingUrl }}" style="color:#206bc4;word-break:break-all;">{{ $billingUrl }}</a></p>
    <p style="margin:0;font-size:14px;line-height:1.5;">{{ __('Terms of sale (version of :date):', ['date' => $termsDateLabel]) }} <a href="{{ $termsUrl }}" style="color:#206bc4;word-break:break-all;">{{ $termsUrl }}</a></p>
@endsection
