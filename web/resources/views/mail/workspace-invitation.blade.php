{!! __('Hello,') !!}

{!! __(':inviter invited you to join the workspace ":workspace" on :app with the role :role.', ['inviter' => $inviter, 'workspace' => $workspace, 'app' => config('app.name'), 'role' => $roleLabel]) !!}

{!! __('To answer, sign in with this email address and open the page of your invitations:') !!}

{!! $pageUrl !!}

{!! __('The invitation is valid until :date.', ['date' => $expiresLabel]) !!}

{!! __('If you were not expecting this invitation, you can ignore this message: nothing happens unless you accept it.') !!}
{{-- Plain-text body: printed raw, HTML escaping would show entities such as &#039; to the reader. --}}
