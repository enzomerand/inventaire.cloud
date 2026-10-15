{!! __('Your account deletion code is: :code', ['code' => $code]) !!}

{!! __('Enter it in the "Delete my account" section of your account page on :app to confirm. It is valid for :minutes minutes and works once. Deleting your account cannot be undone.', ['app' => config('app.name'), 'minutes' => $lifetimeMinutes]) !!}

{!! __('If you did not ask to delete your account, do not share this code: your account is not deleted without it.') !!}
{{-- Plain-text body: printed raw, HTML escaping would show entities such as &#039; to the reader. --}}
