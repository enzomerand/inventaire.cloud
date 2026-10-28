{!! __('Hello,') !!}

{!! __('Stripe reported something that needs your attention on :app:', ['app' => config('app.name')]) !!}

{!! $eventLabel !!}
{!! __('Stripe event: :value', ['value' => $eventType]) !!}
@if ($objectId !== null)
{!! __('Stripe reference: :value', ['value' => $objectId]) !!}
@endif
@if ($amountLabel !== null)
{!! __('Amount: :value', ['value' => $amountLabel]) !!}
@endif
@unless ($livemode)
{!! __('This alert comes from the test mode of Stripe.') !!}
@endunless

{!! __('Look at it in the Stripe dashboard:') !!}

{!! $dashboardUrl !!}
{{-- Plain-text body: printed raw, HTML escaping would show entities such as &#039; to the reader. --}}
