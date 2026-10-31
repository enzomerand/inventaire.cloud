{!! __('Hello :name,', ['name' => $name]) !!}

{!! __('Your Pro subscription to :app is confirmed. This message is the confirmation of your contract, on a durable medium. The terms of sale, which end with the form of withdrawal, are attached (PDF, in French, the version that prevails).', ['app' => config('app.name')]) !!}

{!! __('Plan: Pro, :period', ['period' => $periodLabel]) !!}
{!! __('Workspace: :value', ['value' => $workspaceLabel]) !!}
@if ($priceLabel !== '')
{!! __('Price: :value', ['value' => $priceLabel]) !!}
@endif
@if ($startedLabel !== null)
{!! __('Started on: :value', ['value' => $startedLabel]) !!}
@endif
@if ($periodEndLabel !== null)
{!! __('Current period ends on: :value', ['value' => $periodEndLabel]) !!}
@endif

{!! __('At your express request, the service started immediately, without waiting for the end of the withdrawal period. You acknowledged that you lose your right of withdrawal once the contract has been fully performed.') !!}

@if ($withdrawalUntilLabel !== null)
{!! __('You can withdraw from the contract, without giving a reason, until :moment: use the "Withdraw from the contract here" button on the subscription page, or send the withdrawal form attached to the terms.', ['moment' => $withdrawalUntilLabel]) !!}

@endif
{!! __('You can cancel your contract at any time, free of charge, with the "Cancel your contract" button on the subscription page:') !!}

{!! $billingUrl !!}

{!! __('Terms of sale (version of :date):', ['date' => $termsDateLabel]) !!} {!! $termsUrl !!}
{{-- Plain-text body: printed raw, HTML escaping would show entities such as &#039; to the reader. --}}
