{{--
    The sharing page of an inventory: its share links, which show it read-only to anybody who holds the secret address, without an
    account. For the owners of the workspace. The links are listed (with whether they still work, whether they show the photos and files,
    when they were made, last opened and expire), made (a label, the files or not, a lifetime) and revoked (at once, after a confirmation).

    Alpine runs in its CSP build: the "inventorySharing" component (resources/js/components/inventory-sharing.js) is referenced by name
    and fed by the data-* attributes of its root: the endpoint, whether the administrator allows sharing, and how many links an inventory
    may have. Everything goes through the API. The address of a link comes from the API (it is a secret: nothing here writes it down).

    The card hides and shows its parts with :hidden (applied at once, unlike x-show), so that the cursor can be moved to a part the moment it
    is shown. Nothing that carries :hidden carries a d-* class. Icons: never pass a class attribute to <x-tabler-*> (see
    components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', __('Sharing').' · '.$inventory->name)

@section('content')
    <div
        x-data="inventorySharing"
        data-url="{{ route('api.v1.inventories.share-links.index', ['inventory' => $inventory->ulid], absolute: false) }}"
        data-enabled="{{ $enabled ? '1' : '0' }}"
        data-limit="{{ $limit }}"
    >
        <nav aria-label="{{ __('Breadcrumb') }}">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('app.workspace', ['workspace' => $workspace->ulid], absolute: false) }}">{{ $workspace->name }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('app.inventory', ['workspace' => $workspace->ulid, 'inventory' => $inventory->ulid], absolute: false) }}">{{ $inventory->name }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ __('Sharing') }}</li>
            </ol>
        </nav>

        <div class="d-flex flex-wrap align-items-start gap-3 mb-3">
            <div class="me-auto">
                <h1 class="page-title" tabindex="-1" data-focus="heading">{{ __('Sharing') }}</h1>
                <p class="text-secondary mb-0 mt-1">
                    {{ __('A share link lets anybody who holds it read this inventory, without an account and without being able to change anything: its items, their values and, if you choose, their photos and files. They see nothing of your workspace or of its members. Revoke a link and it stops working at once.') }}
                </p>
            </div>
            <div class="btn-list">
                <a class="btn" href="{{ route('app.inventory', ['workspace' => $workspace->ulid, 'inventory' => $inventory->ulid], absolute: false) }}">
                    <x-tabler-table aria-hidden="true" />
                    {{ __('Back to the items') }}
                </a>
            </div>
        </div>

        {{-- The administrator turned sharing off: the links that exist answer as if they did not, and no other can be made. --}}
        <div class="alert alert-warning" role="status" :hidden="enabled">
            {{ __('Sharing by link is turned off on this instance. The links below do not work until an administrator turns it back on, and no link can be made. You can still revoke them.') }}
        </div>

        <div class="visually-hidden" role="status" aria-live="polite" aria-atomic="true" x-text="announcement"></div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('Share links') }}</h2>
            </div>

            <div class="card-body text-secondary" :hidden="!loading" role="status">
                <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                {{ __('Loading…') }}
            </div>
            <div class="card-body text-secondary" :hidden="!empty">{{ __('This inventory has no share link yet.') }}</div>
            <div class="card-body" :hidden="!loadFailed">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="text-danger" role="alert">{{ __('The share links could not be loaded.') }}</span>
                    <button type="button" class="btn btn-sm" @click="load">{{ __('Try again') }}</button>
                </div>
            </div>

            <ul class="list-group list-group-flush">
                <template x-for="link in links" :key="link.id">
                    <li class="list-group-item">
                        <div class="row align-items-center g-2">
                            <div class="col-12 col-md">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="fw-bold text-break" x-text="linkName(link)"></span>
                                    <span class="badge" :class="stateClass(link)" x-text="stateLabel(link)"></span>
                                    <span class="badge bg-secondary-lt" x-text="filesLabel(link)"></span>
                                    <span class="badge bg-yellow-lt" :hidden="!byFormerMember(link)">{{ __('Created by a former member') }}</span>
                                </div>
                                <div class="text-secondary small">
                                    <span x-text="createdText(link)"></span>
                                    &middot;
                                    <span x-text="lastUsedText(link)"></span>
                                    &middot;
                                    <span x-text="expiresText(link)"></span>
                                </div>

                                <div class="text-secondary small mt-1" :hidden="!byFormerMember(link)">
                                    {{ __('Its author is no longer an owner of this workspace, but the link works until it expires or you revoke it.') }}
                                </div>

                                <div class="input-group input-group-sm mt-2" :hidden="!showsUrl(link)">
                                    <input
                                        type="text"
                                        class="form-control font-monospace"
                                        :value="link.url"
                                        :aria-label="copyNamed(link)"
                                        data-share-action="url"
                                        :data-link-id="link.id"
                                        autocomplete="off"
                                        spellcheck="false"
                                        readonly
                                        @focus="selectUrl"
                                        @click="selectUrl"
                                    >
                                    <button type="button" class="btn" :aria-label="copyNamed(link)" @click="copy(link)">
                                        <x-tabler-copy aria-hidden="true" />
                                        <span x-text="copyLabel(link)"></span>
                                    </button>
                                </div>
                                <div class="text-secondary small mt-2" :hidden="!lacksUrl(link)">
                                    {{ __('The address of this link can no longer be read: revoke it and make a new one.') }}
                                </div>
                            </div>

                            <div class="col-12 col-md-auto">
                                <div :hidden="!isConfirming(link)">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="text-danger" x-text="confirmText(link)"></span>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-danger"
                                            data-share-action="confirm"
                                            :data-link-id="link.id"
                                            :aria-label="revokeLabel(link)"
                                            @click="revoke(link)"
                                            :disabled="isRevoking(link)"
                                        >
                                            <span class="spinner-border spinner-border-sm me-2" x-show="isRevoking(link)" aria-hidden="true"></span>
                                            {{ __('Revoke') }}
                                        </button>
                                        <button type="button" class="btn btn-sm" @click="cancelRevoke" :disabled="isRevoking(link)">{{ __('Cancel') }}</button>
                                    </div>
                                </div>
                                <div :hidden="isConfirming(link)">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        data-share-action="revoke"
                                        :data-link-id="link.id"
                                        :aria-label="revokeLabel(link)"
                                        @click="askRevoke(link)"
                                    >{{ __('Revoke') }}</button>
                                </div>
                            </div>
                        </div>
                    </li>
                </template>
            </ul>

            <div class="card-body border-top" :hidden="!enabled">
                <button type="button" class="btn btn-primary" data-focus="open" :hidden="creating" :disabled="atLimit" @click="open">
                    <x-tabler-plus aria-hidden="true" />
                    {{ __('Create a share link') }}
                </button>
                <div class="form-hint mt-2" :hidden="!atLimit" x-text="limitText"></div>

                <form :hidden="!creating" @submit.prevent="create" aria-labelledby="share-link-new-title" novalidate>
                    <h3 class="h4" id="share-link-new-title">{{ __('New share link') }}</h3>

                    <div class="alert alert-danger" role="alert" :hidden="!formError" x-text="formError"></div>

                    <div class="mb-3">
                        <label class="form-label" for="share-link-label">{{ __('Label') }}</label>
                        <input
                            id="share-link-label"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': errors.label }"
                            x-model="form.label"
                            data-focus="label"
                            maxlength="80"
                            autocomplete="off"
                            aria-describedby="share-link-label-hint share-link-label-error"
                            :aria-invalid="isInvalid('label')"
                        >
                        <div class="form-hint" id="share-link-label-hint">{{ __('Optional. Something to remember who it is for, such as Insurer. Only you see it.') }}</div>
                        <div class="invalid-feedback" id="share-link-label-error" x-text="errors.label"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" x-model="form.includeFiles" aria-describedby="share-link-files-hint">
                            <span class="form-check-label">{{ __('Show the photos and files') }}</span>
                        </label>
                        <div class="form-hint" id="share-link-files-hint">{{ __('Turned off, the columns of photos and files are left out, and no photo or file can be opened through the link.') }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="share-link-expiry">{{ __('Expires after') }}</label>
                        <select
                            id="share-link-expiry"
                            class="form-select"
                            :class="{ 'is-invalid': errors.expires }"
                            x-model="form.expires"
                            aria-describedby="share-link-expiry-error"
                            :aria-invalid="isInvalid('expires')"
                        >
                            <option value="7">{{ __('7 days') }}</option>
                            <option value="30">{{ __('30 days') }}</option>
                            <option value="90">{{ __('90 days') }}</option>
                            <option value="365">{{ __('365 days') }}</option>
                            <option value="">{{ __('Never') }}</option>
                        </select>
                        <div class="invalid-feedback" id="share-link-expiry-error" x-text="errors.expires"></div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button type="submit" class="btn btn-primary" :disabled="!canCreate">
                            <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                            {{ __('Create the link') }}
                        </button>
                        <button type="button" class="btn" @click="cancel" :disabled="busy">{{ __('Cancel') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
