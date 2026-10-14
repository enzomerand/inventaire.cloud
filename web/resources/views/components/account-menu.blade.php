{{--
    The signed-in user's menu in the header: who they are, a link to their space and the sign-out action.

    Alpine runs in its CSP build: the component is referenced by name and gets its values from the data-*
    attributes below. Signing out goes through the API (resources/js/components/account-menu.js), then the
    page reloads on the home page.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@php($user = auth()->user())

<div class="nav-item dropdown" x-data="accountMenu" data-logout-url="{{ route('api.v1.auth.logout', absolute: false) }}" data-home-url="{{ route('home', absolute: false) }}">
    <button
        type="button"
        class="nav-link dropdown-toggle d-flex align-items-center gap-1 bg-transparent border-0"
        data-bs-toggle="dropdown"
        aria-expanded="false"
        aria-label="{{ __('Account menu') }}"
    >
        <x-tabler-user-circle aria-hidden="true" />
        <span class="d-none d-md-inline">{{ $user?->name }}</span>
    </button>

    <div class="dropdown-menu dropdown-menu-end">
        <div class="dropdown-item-text">
            <div class="fw-bold">{{ $user?->name }}</div>
            <div class="text-secondary small">{{ $user?->email }}</div>
        </div>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item" href="{{ route('app') }}">{{ __('My space') }}</a>
        <button type="button" class="dropdown-item" @click="signOut" :disabled="busy">{{ __('Sign out') }}</button>
    </div>
</div>
