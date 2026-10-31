{!! __('Hello :name,', ['name' => $declaredName]) !!}

{!! __('We acknowledge receipt of your declaration of withdrawal from the contract below.') !!}

{!! __('Name: :value', ['value' => $declaredName]) !!}
{!! __('Email address: :value', ['value' => $declaredEmail]) !!}
{!! __('Contract: Pro subscription to :app, :period', ['app' => config('app.name'), 'period' => $periodLabel]) !!}
{!! __('Workspace: :value', ['value' => $workspaceLabel]) !!}
@if ($orderedLabel !== null)
{!! __('Ordered on: :value', ['value' => $orderedLabel]) !!}
@endif
{!! __('Declaration received on: :value', ['value' => $declaredLabel]) !!}

{!! __('Your subscription ends now and the workspace goes back to the Free plan. Nothing is deleted.') !!}

{!! __('We refund the sums you paid, by the same means of payment, without undue delay and at the latest on :date. We deduct an amount proportionate to the service provided until your declaration, calculated pro rata of the days of the period that was paid for.', ['date' => $refundBeforeLabel]) !!}
@if ($refundLabel !== null)

{!! __('Amount to be refunded: :refund', ['refund' => $refundLabel]) !!} {!! __('(paid: :paid; service provided: :used days out of :total)', ['paid' => $paidLabel, 'used' => $daysUsed, 'total' => $daysTotal]) !!}
@endif
{{-- Plain-text body: printed raw, HTML escaping would show entities such as &#039; to the reader. --}}
