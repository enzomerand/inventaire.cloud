{!! __('Hello :name,', ['name' => $name]) !!}

{!! __('An API token was created on your :app account:', ['app' => config('app.name')]) !!}

{!! __('Token name: :value', ['value' => $tokenLabel]) !!}
{!! __('Permissions: :value', ['value' => $abilityLabels]) !!}
{!! __('Created: :value', ['value' => $createdLabel]) !!}
{!! __('Expires: :value', ['value' => $expiresLabel]) !!}
{!! __('IP address: :value', ['value' => $ipLabel]) !!}
{!! __('Browser or application: :value', ['value' => $agentLabel]) !!}

{!! __('If you did not create this token, revoke it now from your account page and sign out everywhere:') !!}

{!! $accountUrl !!}
{{-- Plain-text body: printed raw, HTML escaping would show entities such as &#039; to the reader. --}}
