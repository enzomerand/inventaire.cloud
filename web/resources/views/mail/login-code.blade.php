{!! __('Your sign-in code is: :code', ['code' => $code]) !!}

{!! __('Enter it on the sign-in page of :app. It is valid for :minutes minutes and works once.', ['app' => config('app.name'), 'minutes' => $lifetimeMinutes]) !!}

{!! __('If you did not ask for this code, you can ignore this message.') !!}
{{-- Plain-text body: printed raw, HTML escaping would show entities such as &#039; to the reader. --}}
