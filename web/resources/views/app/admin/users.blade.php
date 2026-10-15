{{--
    Administration, users: the accounts of the instance, to search, create, make administrators and delete.

    Administrators only (the "admin" gate, see routes/web.php).

    Alpine runs in its CSP build: the "adminUsers" component (resources/js/components/admin-users.js) is referenced by
    name and gets its endpoint from the data-* attribute of its root. Everything goes through the API; deleting asks
    for a confirmation in the row. The row of the administrator who is looking has no actions: their own account is
    deleted from their account page, with a code.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', __('Users'))

@section('heading', __('Administration'))

@section('content')
    @include('app.admin.nav', ['active' => 'users'])

    <div class="row row-cards" x-data="adminUsers" data-url="{{ route('api.v1.admin.users.index', absolute: false) }}">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('Accounts') }}</h2>
                </div>
                <div class="card-body border-bottom">
                    <form class="d-flex gap-2" role="search" @submit.prevent="search" novalidate>
                        <label class="visually-hidden" for="admin-users-search">{{ __('Search by name or email address') }}</label>
                        <input
                            id="admin-users-search"
                            type="search"
                            class="form-control"
                            x-model="query"
                            maxlength="255"
                            autocomplete="off"
                            placeholder="{{ __('Search by name or email address') }}"
                        >
                        <button type="submit" class="btn" :disabled="loading">
                            <x-tabler-search aria-hidden="true" />
                            {{ __('Search') }}
                        </button>
                    </form>
                </div>
                <div class="list-group list-group-flush">
                    <div class="list-group-item text-secondary" x-show="loading" role="status">
                        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                        {{ __('Loading…') }}
                    </div>

                    <template x-for="item in items" :key="item.id">
                        <div class="list-group-item">
                            <div class="row align-items-center g-2">
                                <div class="col-12 col-md">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="fw-bold text-break" x-text="item.name"></span>
                                        <span class="badge bg-primary-lt" x-show="item.is_admin">{{ __('Administrator') }}</span>
                                        <span class="badge" x-show="isSelf(item)">{{ __('You') }}</span>
                                    </div>
                                    <div class="text-secondary text-break" x-text="item.email"></div>
                                    <div class="text-secondary small">
                                        <span x-text="workspacesLabel(item)"></span>
                                        &middot;
                                        <span x-text="createdLabel(item)"></span>
                                    </div>
                                </div>

                                <div class="col-12 col-md-auto" x-show="!isSelf(item)">
                                    <div x-show="isConfirming(item)">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="text-danger" x-text="confirmText(item)"></span>
                                            <button type="button" class="btn btn-danger" @click="confirmDelete(item)" :disabled="busy">{{ __('Delete') }}</button>
                                            <button type="button" class="btn" @click="cancelDelete" :disabled="busy">{{ __('Cancel') }}</button>
                                        </div>
                                    </div>

                                    <div x-show="!isConfirming(item)">
                                        <div class="d-flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                class="btn btn-sm"
                                                :aria-label="toggleLabel(item)"
                                                :title="toggleLabel(item)"
                                                @click="toggleAdmin(item)"
                                                :disabled="busy"
                                                x-text="toggleText(item)"
                                            ></button>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-icon btn-outline-danger"
                                                :aria-label="deleteLabel(item)"
                                                :title="deleteLabel(item)"
                                                @click="askDelete(item)"
                                                :disabled="busy"
                                            >
                                                <x-tabler-trash aria-hidden="true" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div class="list-group-item text-secondary" x-show="empty">{{ __('No account found.') }}</div>
                </div>
                <div class="card-footer d-flex align-items-center gap-2">
                    <p class="m-0 text-secondary" x-text="rangeLabel"></p>
                    <div class="btn-list ms-auto">
                        <button type="button" class="btn btn-sm" @click="previous" :disabled="!hasPrevious || loading">{{ __('Previous') }}</button>
                        <button type="button" class="btn btn-sm" @click="next" :disabled="!hasNext || loading">{{ __('Next') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('New account') }}</h2>
                </div>
                <form class="card-body" @submit.prevent="create" novalidate>
                    <p class="text-secondary">{{ __('The person receives an email that tells them how to sign in. There is no password: a code is emailed each time.') }}</p>

                    <div class="alert alert-danger" role="alert" x-show="formError" x-text="formError"></div>

                    <div class="mb-3">
                        <label class="form-label" for="admin-users-new-email">{{ __('Email address') }}</label>
                        <input
                            id="admin-users-new-email"
                            type="email"
                            class="form-control"
                            x-model="newEmail"
                            autocomplete="off"
                            inputmode="email"
                            autocapitalize="off"
                            spellcheck="false"
                            required
                        >
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="admin-users-new-name">{{ __('Name') }}</label>
                        <input
                            id="admin-users-new-name"
                            type="text"
                            class="form-control"
                            x-model="newName"
                            maxlength="255"
                            autocomplete="off"
                            required
                        >
                    </div>
                    <button type="submit" class="btn btn-primary w-100" :disabled="!canCreate">
                        <x-tabler-user-plus aria-hidden="true" />
                        {{ __('Create the account') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
