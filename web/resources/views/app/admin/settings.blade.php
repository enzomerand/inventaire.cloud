{{--
    Administration, settings: the choices that apply to the whole instance.

    Administrators only (the "admin" gate, see routes/web.php).

    Alpine runs in its CSP build: the "adminSettings" component (resources/js/components/admin-settings.js) is
    referenced by name and gets its endpoint and the current values from the data-* attributes of its root, which holds
    both cards. Flipping the switch saves it through the API. The card of the e-signature service (Documenso) is
    read-only: where it stands comes from the environment of the instance ($documenso, see AdminSettingsPageController),
    and the address of its webhook can be copied.

    Do not put a display utility (d-flex...) on an element that carries :hidden: the utility wins and it never hides.
    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', __('Settings'))

@section('heading', __('Administration'))

@section('content')
    @include('app.admin.nav', ['active' => 'settings'])

    @inject('settings', 'App\Settings\InstanceSettings')

    <div
        class="row row-cards"
        x-data="adminSettings"
        data-url="{{ route('api.v1.admin.settings.show', absolute: false) }}"
        data-registration-open="{{ $settings->registrationOpen() ? '1' : '0' }}"
        data-invitation-sign-up="{{ $settings->invitationSignUp() ? '1' : '0' }}"
        data-documenso-configured="{{ $documenso['configured'] ? '1' : '0' }}"
        data-documenso-secret="{{ $documenso['webhook_secret_configured'] ? '1' : '0' }}"
        data-webhook-url="{{ $documenso['webhook_url'] }}"
    >
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('Sign-ups') }}</h2>
                </div>
                <div class="card-body">
                    <label class="form-check form-switch">
                        <input
                            id="admin-settings-registration"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            :checked="registrationOpen"
                            :disabled="busy"
                            @change="toggleRegistration"
                        >
                        <span class="form-check-label">{{ __('Anybody can create an account') }}</span>
                    </label>
                    <div class="form-hint mt-2">
                        {{ __('When this is off, only people who already have an account can sign in, and the ones invited to a workspace as long as the switch below is on: nobody else receives a code. You can still create accounts from the Users page.') }}
                    </div>

                    <label class="form-check form-switch mt-4">
                        <input
                            id="admin-settings-invitation-sign-up"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            :checked="invitationSignUp"
                            :disabled="busy"
                            aria-describedby="admin-settings-invitation-sign-up-hint"
                            @change="toggleInvitationSignUp"
                        >
                        <span class="form-check-label">{{ __('An invitation to a workspace lets its address create an account') }}</span>
                    </label>
                    <div class="form-hint mt-2" id="admin-settings-invitation-sign-up-hint">
                        {{ __('Only while sign-ups are closed, which then means on invitation: an address that a person with an account invited to a workspace gets its code and can create its account. Turn it off for accounts to come from you alone.') }}
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('Electronic signature (Documenso)') }}</h2>
                </div>
                <div class="card-body">
                    <div class="visually-hidden" role="status" aria-live="polite" aria-atomic="true" x-text="announcement"></div>

                    <div class="mb-3">
                        <div class="form-label">{{ __('Signature service') }}</div>
                        <span class="badge bg-green-lt" :hidden="!documensoConfigured">{{ __('Configured') }}</span>
                        <span class="badge bg-secondary-lt" :hidden="documensoConfigured">{{ __('Not configured') }}</span>
                        <div class="form-hint mt-1" :hidden="documensoConfigured">
                            {{ __('Until it is, official versions cannot be sent to be signed.') }}
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-label">{{ __('Webhook secret') }}</div>
                        <span class="badge bg-green-lt" :hidden="!webhookSecretConfigured">{{ __('Configured') }}</span>
                        <span class="badge bg-secondary-lt" :hidden="webhookSecretConfigured">{{ __('Not configured') }}</span>
                        <div class="form-hint mt-1" :hidden="webhookSecretConfigured">
                            {{ __('Without a secret, the webhook address below does not answer.') }}
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="admin-settings-webhook">{{ __('Webhook address') }}</label>
                        <div class="input-group">
                            <input
                                id="admin-settings-webhook"
                                type="text"
                                class="form-control font-monospace"
                                :value="webhookUrl"
                                x-ref="webhook"
                                aria-describedby="admin-settings-webhook-hint"
                                autocomplete="off"
                                spellcheck="false"
                                readonly
                                @focus="select"
                                @click="select"
                            >
                            <button type="button" class="btn" @click="copyWebhook">
                                <x-tabler-copy aria-hidden="true" />
                                <span x-text="copyLabel"></span>
                            </button>
                        </div>
                        <div class="form-hint" id="admin-settings-webhook-hint">
                            {{ __('Give this address to Documenso as the webhook of your team, with the same secret. Without a webhook that Documenso can reach, the statuses of the signatures are refreshed every 10 minutes.') }}
                        </div>
                    </div>

                    <div class="form-hint">
                        {{ __('These values are set in the environment of the instance, not here:') }}
                        <code>DOCUMENSO_URL</code>, <code>DOCUMENSO_API_KEY</code>, <code>DOCUMENSO_WEBHOOK_SECRET</code>.
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
