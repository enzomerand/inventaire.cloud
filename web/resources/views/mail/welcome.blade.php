{!! __('Hello :name,', ['name' => $name]) !!}

{!! __('An administrator created an account for you on :app, with this email address.', ['app' => config('app.name')]) !!}

{!! __('There is no password: open the page below, enter this email address and type the 6-digit code we email you.') !!}

{!! $loginUrl !!}

{!! __('If you were not expecting this message, you can ignore it.') !!}
{{-- Plain-text body: printed raw, HTML escaping would show entities such as &#039; to the reader. --}}
