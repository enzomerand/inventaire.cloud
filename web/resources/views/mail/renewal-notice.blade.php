{!! __('Hello :name,', ['name' => $name]) !!}

{!! __('Your yearly Pro subscription to :app for the workspace :workspace renews automatically for one year when the period that is paid for ends.', ['app' => config('app.name'), 'workspace' => $workspaceLabel]) !!}

=====================================================
{!! __('Last date to refuse the renewal: :date', ['date' => $deadlineLabel]) !!}
=====================================================

@if ($priceLabel !== '')
{!! __('If you do nothing, the renewal is charged :price.', ['price' => $priceLabel]) !!}

@endif
{!! __('You can refuse the renewal, free of charge, until that date: cancel your contract from the subscription page. It then ends on that date and the workspace goes back to the Free plan; nothing is deleted.') !!}

{!! __('Cancel your contract') !!}: {!! $billingUrl !!}
{{-- Plain-text body: printed raw, HTML escaping would show entities such as &#039; to the reader. --}}
