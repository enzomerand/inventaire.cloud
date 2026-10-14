{!! __('Your verification code is: :code', ['code' => $code]) !!}

{!! __('Enter it in the installation wizard to confirm that this instance can send email. It is valid for :minutes minutes.', ['minutes' => $lifetimeMinutes]) !!}

{!! __('If you did not ask for this code, you can ignore this message.') !!}
{{-- Plain-text body: printed raw, HTML escaping would show entities such as &#039; to the reader. --}}
