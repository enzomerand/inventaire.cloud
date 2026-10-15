{{--
    Administration, settings: the choices that apply to the whole instance.

    Administrators only (the "admin" gate, see routes/web.php).

    Alpine runs in its CSP build: the "adminSettings" component (resources/js/components/admin-settings.js) is
    referenced by name and gets its endpoint and the current value from the data-* attributes of its root. Flipping
    the switch saves it through the API.
--}}
@extends('layouts.app')

@section('title', __('Settings'))

@section('heading', __('Administration'))

@section('content')
    @include('app.admin.nav', ['active' => 'settings'])

    @inject('settings', 'App\Settings\InstanceSettings')

    <div class="row row-cards">
        <div class="col-12 col-lg-8">
            <div
                class="card"
                x-data="adminSettings"
                data-url="{{ route('api.v1.admin.settings.show', absolute: false) }}"
                data-registration-open="{{ $settings->registrationOpen() ? '1' : '0' }}"
            >
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
                        {{ __('When this is off, only people who already have an account can sign in, and nobody receives a code for an address that has none. You can still create accounts from the Users page.') }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
