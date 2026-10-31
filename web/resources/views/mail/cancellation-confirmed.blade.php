{!! __('Hello :name,', ['name' => $name]) !!}

{!! __('We received the cancellation of your Pro subscription to :app.', ['app' => config('app.name')]) !!}

{!! __('Plan: Pro, :period', ['period' => $periodLabel]) !!}
{!! __('Workspace: :value', ['value' => $workspaceLabel]) !!}
{!! __('Your contract ends on: :value', ['value' => $endsLabel]) !!}

{!! __('Pro stays available until then and you will not be charged again. The period already paid is not refunded, unless the law provides otherwise.') !!}

{!! __('On that date the workspace goes back to the Free plan. Nothing is deleted: what goes beyond the limits of the Free plan stays, and creating more is refused until the workspace is back within them. You can export your data at any time.') !!}

{!! __('Changed your mind? You can keep your subscription from the subscription management until that date:') !!}

{!! $billingUrl !!}
{{-- Plain-text body: printed raw, HTML escaping would show entities such as &#039; to the reader. --}}
