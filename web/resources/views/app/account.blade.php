{{--
    "My account": the profile, the security of the sessions, the API tokens, the person's data and the deletion of the account.

    Alpine runs in its CSP build: each card is a component referenced by name (resources/js/components/account-*.js)
    that gets its endpoints and values from the data-* attributes of its root, never from Blade inside an
    expression. Everything goes through the API.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', __('My account'))

@section('heading', __('My account'))

@section('content')
    @php
        $user = auth()->user();
        $locales = config()->array('app.locale_names');
        $accountLocale = $user?->locale !== null && array_key_exists($user->locale, $locales) ? $user->locale : app()->getLocale();
    @endphp

    <div class="row row-cards">
        <div class="col-12 col-lg-8">
            <div class="d-flex flex-column gap-3">
                <div
                    class="card"
                    x-data="accountProfile"
                    data-url="{{ route('api.v1.me.update', absolute: false) }}"
                    data-name="{{ $user?->name }}"
                    data-locale="{{ $accountLocale }}"
                >
                    <div class="card-header">
                        <h2 class="card-title">{{ __('Profile') }}</h2>
                    </div>
                    <form class="card-body" @submit.prevent="save" novalidate>
                        <div class="mb-3">
                            <label class="form-label" for="account-name">{{ __('Name') }}</label>
                            <input id="account-name" type="text" class="form-control" x-model="name" maxlength="255" autocomplete="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="account-email">{{ __('Email address') }}</label>
                            <input id="account-email" type="email" class="form-control" value="{{ $user?->email }}" readonly>
                            <div class="form-hint">{{ __('You sign in with this address, and it receives the codes.') }}</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="account-locale">{{ __('Language') }}</label>
                            <select id="account-locale" class="form-select" x-model="locale">
                                @foreach ($locales as $code => $name)
                                    <option value="{{ $code }}" lang="{{ $code }}" @selected($code === $accountLocale)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary" :disabled="!canSave">
                            <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                            {{ __('Save') }}
                        </button>
                    </form>
                </div>

                <div class="card" x-data="accountSecurity" data-url="{{ route('api.v1.me.sign-out-everywhere', absolute: false) }}">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('Security') }}</h2>
                    </div>
                    <div class="card-body">
                        <p>{{ __('Signed in on a device you no longer use, or lost a phone? Sign out of all your other devices: they will ask for a new code, and your API tokens stop working. This device stays signed in.') }}</p>
                        <button type="button" class="btn" x-show="!confirming" @click="ask">
                            <x-tabler-logout aria-hidden="true" />
                            {{ __('Sign out everywhere') }}
                        </button>
                        <div x-show="confirming">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span>{{ __('Sign out of all your other devices?') }}</span>
                                <button type="button" class="btn btn-primary" @click="signOutEverywhere" :disabled="busy">
                                    <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                                    {{ __('Sign out everywhere') }}
                                </button>
                                <button type="button" class="btn" @click="cancel" :disabled="busy">{{ __('Cancel') }}</button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- API tokens: the card hides and shows its parts with :hidden (applied at once, unlike x-show), so that the cursor can be
                     moved to a part the moment it is shown. Nothing that carries :hidden carries a d-* class. The card reads its list
                     again when the security card revokes every token (the "tokens-revoked" event of the window). --}}
                <div
                    class="card"
                    x-data="accountTokens"
                    data-url="{{ route('api.v1.me.tokens.index', absolute: false) }}"
                    @tokens-revoked.window="load"
                >
                    <div class="card-header">
                        <h2 class="card-title">{{ __('API tokens') }}</h2>
                    </div>
                    <div class="card-body">
                        <p>{{ __('An API token lets a script or another application use your inventories without signing in. It acts with your rights in your workspaces, never beyond them, and stops working when it expires or when you revoke it.') }}</p>
                        <p class="mb-0">
                            <a href="{{ route('docs.api', absolute: false) }}">{{ __('API documentation') }}</a>
                        </p>
                    </div>

                    <div class="visually-hidden" role="status" aria-live="polite" aria-atomic="true" x-text="announcement"></div>

                    <div class="card-body border-top pb-2">
                        <h3 class="h4 mb-0" tabindex="-1" data-focus="heading">{{ __('Active tokens') }}</h3>
                    </div>
                    <div class="card-body pt-0 text-secondary" :hidden="!loading" role="status">
                        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                        {{ __('Loading…') }}
                    </div>
                    <div class="card-body pt-0 text-secondary" :hidden="!empty">{{ __('You have no API token yet.') }}</div>
                    <div class="card-body pt-0" :hidden="!loadFailed">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="text-danger" role="alert">{{ __('Your API tokens could not be loaded.') }}</span>
                            <button type="button" class="btn btn-sm" @click="load">{{ __('Try again') }}</button>
                        </div>
                    </div>
                    <ul class="list-group list-group-flush">
                        <template x-for="token in tokens" :key="token.id">
                            <li class="list-group-item">
                                <div class="row align-items-center g-2">
                                    <div class="col-12 col-md">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="fw-bold text-break" x-text="token.name"></span>
                                            <template x-for="ability in token.abilities" :key="ability">
                                                <span class="badge" :class="abilityClass(ability)" x-text="abilityLabel(ability)"></span>
                                            </template>
                                        </div>
                                        <div class="text-secondary small">
                                            <span x-text="createdText(token)"></span>
                                            &middot;
                                            <span x-text="lastUsedText(token)"></span>
                                            &middot;
                                            <span x-text="expiresText(token)"></span>
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-auto">
                                        <div :hidden="!isConfirming(token)">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <span class="text-danger" x-text="confirmText(token)"></span>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-danger"
                                                    data-token-action="confirm"
                                                    :data-token-id="token.id"
                                                    :aria-label="revokeLabel(token)"
                                                    @click="revoke(token)"
                                                    :disabled="isRevoking(token)"
                                                >
                                                    <span class="spinner-border spinner-border-sm me-2" x-show="isRevoking(token)" aria-hidden="true"></span>
                                                    {{ __('Revoke') }}
                                                </button>
                                                <button type="button" class="btn btn-sm" @click="cancelRevoke" :disabled="isRevoking(token)">{{ __('Cancel') }}</button>
                                            </div>
                                        </div>
                                        <div :hidden="isConfirming(token)">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                data-token-action="revoke"
                                                :data-token-id="token.id"
                                                :aria-label="revokeLabel(token)"
                                                @click="askRevoke(token)"
                                            >{{ __('Revoke') }}</button>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        </template>
                    </ul>

                    <div class="card-body border-top">
                        <button type="button" class="btn btn-primary" data-focus="open" :hidden="!canOpen" @click="open">
                            <x-tabler-plus aria-hidden="true" />
                            {{ __('Create a token') }}
                        </button>

                        <form :hidden="!creating" @submit.prevent="create" aria-labelledby="api-token-new-title" novalidate>
                            <h3 class="h4" id="api-token-new-title">{{ __('New API token') }}</h3>

                            <div class="alert alert-danger" role="alert" :hidden="!formError" x-text="formError"></div>

                            <div class="mb-3">
                                <label class="form-label required" for="api-token-name">{{ __('Name') }}</label>
                                <input
                                    id="api-token-name"
                                    type="text"
                                    class="form-control"
                                    :class="{ 'is-invalid': errors.name }"
                                    x-model="form.name"
                                    data-focus="name"
                                    maxlength="80"
                                    autocomplete="off"
                                    aria-describedby="api-token-name-hint api-token-name-error"
                                    :aria-invalid="isInvalid('name')"
                                    required
                                >
                                <div class="form-hint" id="api-token-name-hint">{{ __('Something to remember what it is for, such as Stock sync.') }}</div>
                                <div class="invalid-feedback" id="api-token-name-error" x-text="errors.name"></div>
                            </div>

                            <fieldset class="mb-3" aria-describedby="api-token-abilities-hint api-token-abilities-error">
                                <legend class="form-label required">{{ __('Permissions') }}</legend>
                                <label class="form-check">
                                    <input type="checkbox" class="form-check-input" name="abilities" value="read" x-model="form.abilities">
                                    <span class="form-check-label">{{ __('Read') }}</span>
                                    <span class="form-check-description">{{ __('See your workspaces, inventories, items and files.') }}</span>
                                </label>
                                <label class="form-check">
                                    <input type="checkbox" class="form-check-input" name="abilities" value="write" x-model="form.abilities">
                                    <span class="form-check-label">{{ __('Write') }}</span>
                                    <span class="form-check-description">{{ __('Add, change and delete items and files, and run exports and imports.') }}</span>
                                </label>
                                <label class="form-check">
                                    <input type="checkbox" class="form-check-input" name="abilities" value="manage" x-model="form.abilities">
                                    <span class="form-check-label">{{ __('Manage') }}</span>
                                    <span class="form-check-description">{{ __('Create, change and delete workspaces, inventories and fields, including deleting a workspace or an inventory with all its data.') }}</span>
                                </label>
                                <div class="form-hint" id="api-token-abilities-hint">{{ __('Choose at least one. Each permission stands alone: without Read a token can hardly be used, since the identifiers are found by reading. What a token writes is sent back to it.') }}</div>
                                <div class="text-danger small" id="api-token-abilities-error" :hidden="!errors.abilities" x-text="errors.abilities"></div>
                            </fieldset>

                            <div class="mb-3">
                                <label class="form-label" for="api-token-expiry">{{ __('Expires after') }}</label>
                                <select
                                    id="api-token-expiry"
                                    class="form-select"
                                    :class="{ 'is-invalid': errors.expires }"
                                    x-model="form.expires"
                                    aria-describedby="api-token-expiry-error"
                                    :aria-invalid="isInvalid('expires')"
                                >
                                    <option value="7">{{ __('7 days') }}</option>
                                    <option value="30">{{ __('30 days') }}</option>
                                    <option value="90">{{ __('90 days') }}</option>
                                    <option value="365">{{ __('365 days') }}</option>
                                    <option value="">{{ __('Never') }}</option>
                                </select>
                                <div class="invalid-feedback" id="api-token-expiry-error" x-text="errors.expires"></div>
                            </div>

                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <button type="submit" class="btn btn-primary" :disabled="!canCreate">
                                    <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                                    {{ __('Create the token') }}
                                </button>
                                <button type="button" class="btn" @click="cancel" :disabled="busy">{{ __('Cancel') }}</button>
                            </div>
                        </form>

                        <div :hidden="!hasRevealed" role="group" aria-labelledby="api-token-revealed-title">
                            <h3 class="h4" id="api-token-revealed-title">{{ __('Your new token') }}</h3>
                            <div class="alert alert-warning" id="api-token-warning">{{ __('Copy it now: it will not be shown again. Anyone who has it can act with your rights.') }}</div>
                            <label class="form-label" for="api-token-value">{{ __('API token') }}</label>
                            <div class="input-group mb-3">
                                <input
                                    id="api-token-value"
                                    type="text"
                                    class="form-control font-monospace"
                                    :value="revealed"
                                    data-focus="token"
                                    aria-describedby="api-token-warning"
                                    autocomplete="off"
                                    spellcheck="false"
                                    readonly
                                    @focus="select"
                                    @click="select"
                                >
                                <button type="button" class="btn" @click="copy">
                                    <x-tabler-copy aria-hidden="true" />
                                    <span x-text="copyLabel"></span>
                                </button>
                            </div>
                            <button type="button" class="btn btn-primary" @click="dismiss">{{ __('I have copied the token') }}</button>
                        </div>
                    </div>
                </div>

                <div class="card" x-data="accountData" data-url="{{ route('api.v1.me.export', absolute: false) }}">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('Your data') }}</h2>
                    </div>
                    <div class="card-body">
                        <p>{{ __('Download everything this application holds about your account: your profile and your workspaces, as a JSON file.') }}</p>
                        <button type="button" class="btn" @click="download" :disabled="busy">
                            <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                            <x-tabler-download aria-hidden="true" x-show="!busy" />
                            {{ __('Download my data') }}
                        </button>
                    </div>
                </div>

                <div
                    class="card border-danger"
                    x-data="accountDeletion"
                    data-code-url="{{ route('api.v1.me.deletion-code', absolute: false) }}"
                    data-url="{{ route('api.v1.me.destroy', absolute: false) }}"
                    data-home-url="{{ route('home', absolute: false) }}"
                >
                    <div class="card-header">
                        <h2 class="card-title text-danger">{{ __('Delete my account') }}</h2>
                    </div>
                    <div class="card-body">
                        <p>{{ __('Deleting your account erases your profile and the workspaces that only you belong to, with everything in them. You simply leave the workspaces you share with other people. This cannot be undone.') }}</p>
                        @if (\App\Billing\Billing::enabled())
                            <p>{{ __('If one of those workspaces has a Pro subscription, it ends at once, and the period in progress is not refunded, subject to your right of withdrawal and the legal guarantee. If you are within the 14 days of withdrawal, withdraw from the contract first from the Subscription page of that workspace: deleting the workspace removes that page.') }}</p>
                        @endif

                        <div class="alert alert-danger" role="alert" x-show="error">
                            <div x-text="error"></div>
                            <ul class="mb-0 mt-2" x-show="blocking.length > 0">
                                <template x-for="workspace in blocking" :key="workspace.id">
                                    <li x-text="workspace.name"></li>
                                </template>
                            </ul>
                        </div>

                        <button type="button" class="btn btn-outline-danger" x-show="phase === 'idle'" @click="start" :disabled="busy">
                            <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                            {{ __('Delete my account…') }}
                        </button>

                        <form x-show="phase === 'code'" @submit.prevent="confirm" novalidate>
                            <p>{{ __('We sent a 6-digit code to your email address. It is valid for :minutes minutes.', ['minutes' => config('auth.account_deletion.lifetime_minutes')]) }}</p>
                            <div class="mb-3">
                                <label class="form-label" for="account-deletion-code">{{ __('Code') }}</label>
                                <input
                                    id="account-deletion-code"
                                    type="text"
                                    class="form-control form-control-lg text-center"
                                    x-model="code"
                                    x-ref="code"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    pattern="[0-9 ]*"
                                    spellcheck="false"
                                >
                            </div>
                            <label class="form-check mb-3">
                                <input type="checkbox" class="form-check-input" x-model="understood">
                                <span class="form-check-label">{{ __('I understand that my account and its data will be deleted for good.') }}</span>
                            </label>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <button type="submit" class="btn btn-danger" :disabled="busy || !ready">
                                    <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                                    {{ __('Delete my account for good') }}
                                </button>
                                <button type="button" class="btn" @click="cancel" :disabled="busy">{{ __('Cancel') }}</button>
                                <button type="button" class="btn btn-link ms-auto" @click="resend" :disabled="busy">{{ __('Send a new code') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
