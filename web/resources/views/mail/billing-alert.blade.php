{!! __('Hello,') !!}

@if ($problem)
{!! __('Something about the payments needs your attention on :app:', ['app' => config('app.name')]) !!}
@elseif ($task)
{!! __('Something needs to be done by you on :app:', ['app' => config('app.name')]) !!}
@else
{!! __('Stripe reported something that needs your attention on :app:', ['app' => config('app.name')]) !!}
@endif

{!! $eventLabel !!}
@if ($problem)
{!! $explanation !!}

@if ($objectId !== null)
{!! __('Stripe customer: :value', ['value' => $objectId]) !!}
@endif
@if ($workspaceUlid !== null)
{!! __('Workspace: :value', ['value' => $workspaceUlid]) !!}
@endif
@elseif ($task)
{!! $explanation !!}

@if ($objectId !== null)
{!! __('Stripe customer: :value', ['value' => $objectId]) !!}
@endif
@if ($workspaceUlid !== null)
{!! __('Workspace: :value', ['value' => $workspaceUlid]) !!}
@endif
@foreach ($taskLines as $line)
{!! $line !!}
@endforeach
@else
{!! __('Stripe event: :value', ['value' => $eventType]) !!}
@if ($objectId !== null)
{!! __('Stripe reference: :value', ['value' => $objectId]) !!}
@endif
@if ($amountLabel !== null)
{!! __('Amount: :value', ['value' => $amountLabel]) !!}
@endif
@endif
@unless ($livemode)
{!! __('This alert comes from the test mode of Stripe.') !!}
@endunless

{!! __('Look at it in the Stripe dashboard:') !!}

{!! $dashboardUrl !!}
{{-- Plain-text body: printed raw, HTML escaping would show entities such as &#039; to the reader. --}}
