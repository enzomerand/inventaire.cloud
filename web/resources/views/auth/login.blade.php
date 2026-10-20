{{--
    Sign-in page. There are no passwords: the visitor gives an email address, types the 6-digit code that is sent
    to it and, when the address has no account yet, gives a name. The Alpine "login" component
    (resources/js/components/login.js) drives the three phases through the /api/v1/auth endpoints.

    Alpine runs in its CSP build: expressions stay simple (property access, method calls, comparisons) and the
    values the component needs come from the data-* attributes of its root, never from Blade inside an expression.

    When an administrator has closed registration the page says so, to everybody alike: it names no address and
    reveals nothing about any (the sign-in steps answer the same for an address that has an account, one that has an
    invitation waiting and one that has neither).
--}}
@extends('layouts.app')

@section('title', __('Sign in'))

@section('content')
    @inject('settings', 'App\Settings\InstanceSettings')

    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5 col-xl-4">
            <div
                class="card"
                x-data="login"
                data-code-url="{{ route('api.v1.auth.code', absolute: false) }}"
                data-verify-url="{{ route('api.v1.auth.verify', absolute: false) }}"
                data-app-url="{{ route('app', absolute: false) }}"
            >
                <div class="card-body">
                    <h1 class="card-title h2 mb-1">{{ __('Sign in') }}</h1>
                    @if ($settings->registrationOpen())
                        <p class="text-secondary">{{ __('No password needed: we email you a 6-digit code. If you are new here, the same steps create your account.') }}</p>
                    @else
                        <p class="text-secondary">{{ __('No password needed: we email you a 6-digit code.') }}</p>
                        <div class="alert alert-info" role="note">{{ $settings->invitationSignUp() ? __('Sign-ups are closed: only existing accounts and invited addresses can sign in.') : __('Sign-ups are closed: only existing accounts can sign in.') }}</div>
                    @endif

                    <div class="alert alert-danger" role="alert" x-show="error" x-text="error"></div>

                    {{-- Phase 1: the email address --}}
                    <form x-show="phase === 'email'" @submit.prevent="requestCode" novalidate>
                        <div class="mb-3">
                            <label class="form-label" for="login-email">{{ __('Email address') }}</label>
                            <input
                                id="login-email"
                                type="email"
                                class="form-control"
                                x-model="email"
                                x-ref="email"
                                autocomplete="email"
                                inputmode="email"
                                autocapitalize="off"
                                spellcheck="false"
                                autofocus
                                required
                            >
                        </div>
                        <button type="submit" class="btn btn-primary w-100" :disabled="busy">
                            <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                            {{ __('Send me a code') }}
                        </button>
                    </form>

                    {{-- Phase 2: the code that was emailed --}}
                    <form x-show="phase === 'code'" @submit.prevent="verify" novalidate>
                        <p>
                            {{ __('We sent a 6-digit code to the address below. It is valid for :minutes minutes.', ['minutes' => config('auth.login_codes.lifetime_minutes')]) }}
                        </p>
                        <p class="fw-bold text-break" x-text="email"></p>
                        <div class="mb-3">
                            <label class="form-label" for="login-code">{{ __('Code') }}</label>
                            <input
                                id="login-code"
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
                        <button type="submit" class="btn btn-primary w-100" :disabled="busy">
                            <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                            {{ __('Sign in') }}
                        </button>
                        <div class="d-flex flex-wrap justify-content-between mt-3">
                            <button type="button" class="btn btn-link p-0" @click="back" :disabled="busy">{{ __('Use another address') }}</button>
                            <button type="button" class="btn btn-link p-0" @click="resend" :disabled="busy">{{ __('Send a new code') }}</button>
                        </div>
                    </form>

                    {{-- Phase 3: the name, for an address that has no account yet --}}
                    <form x-show="phase === 'name'" @submit.prevent="verify" novalidate>
                        <p>{{ __('Welcome! This address has no account yet. Tell us your name to create it.') }}</p>
                        <div class="mb-3">
                            <label class="form-label" for="login-name">{{ __('Name') }}</label>
                            <input
                                id="login-name"
                                type="text"
                                class="form-control"
                                x-model="name"
                                x-ref="name"
                                autocomplete="name"
                                maxlength="255"
                            >
                        </div>
                        <button type="submit" class="btn btn-primary w-100" :disabled="busy">
                            <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                            {{ __('Create my account') }}
                        </button>
                        <div class="mt-3">
                            <button type="button" class="btn btn-link p-0" @click="back" :disabled="busy">{{ __('Use another address') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
