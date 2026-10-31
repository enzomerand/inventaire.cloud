{{-- HTML body of the acknowledgement of a withdrawal: the declaration, the date and time it was received, and the effects. --}}
@extends('mail.contract-layout')

@section('title', __('Acknowledgement of your withdrawal from the :app contract', ['app' => config('app.name')]))

@section('content')
    <p style="margin:0 0 16px;font-size:16px;">{{ __('Hello :name,', ['name' => $declaredName]) }}</p>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('We acknowledge receipt of your declaration of withdrawal from the contract below.') }}</p>
    <ul style="margin:0 0 16px;padding:0 0 0 20px;font-size:14px;line-height:1.6;">
        <li>{{ __('Name: :value', ['value' => $declaredName]) }}</li>
        <li>{{ __('Email address: :value', ['value' => $declaredEmail]) }}</li>
        <li>{{ __('Contract: Pro subscription to :app, :period', ['app' => config('app.name'), 'period' => $periodLabel]) }}</li>
        <li>{{ __('Workspace: :value', ['value' => $workspaceLabel]) }}</li>
        @if ($orderedLabel !== null)
            <li>{{ __('Ordered on: :value', ['value' => $orderedLabel]) }}</li>
        @endif
        <li><strong>{{ __('Declaration received on: :value', ['value' => $declaredLabel]) }}</strong></li>
    </ul>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('Your subscription ends now and the workspace goes back to the Free plan. Nothing is deleted.') }}</p>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('We refund the sums you paid, by the same means of payment, without undue delay and at the latest on :date. We deduct an amount proportionate to the service provided until your declaration, calculated pro rata of the days of the period that was paid for.', ['date' => $refundBeforeLabel]) }}</p>
    @if ($refundLabel !== null)
        <p style="margin:0;font-size:14px;line-height:1.5;"><strong>{{ __('Amount to be refunded: :refund', ['refund' => $refundLabel]) }}</strong> {{ __('(paid: :paid; service provided: :used days out of :total)', ['paid' => $paidLabel, 'used' => $daysUsed, 'total' => $daysTotal]) }}</p>
    @endif
@endsection
