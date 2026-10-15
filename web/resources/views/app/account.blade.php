{{--
    "My account": the profile, the security of the sessions, the person's data and the deletion of the account.

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
