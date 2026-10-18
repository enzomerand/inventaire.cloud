{{--
    The official versions of an inventory: the inventory frozen as numbered, dated versions, each with its data (JSON), its PDF and,
    when signatures were asked for, the people who must sign and where each one stands. Every member of the workspace reads and
    downloads; editors and owners freeze the inventory (the dialog "New version"), cancel a request for signatures and send it
    again; owners delete a version.

    Alpine runs in its CSP build: the "inventoryVersions" component (resources/js/components/inventory-versions.js, with
    resources/js/versions/versions.js) is referenced by name and fed by the data-* attributes of its root: the endpoints (__id__
    stands for the ULID of a version), what the role allows, whether the instance can ask for signatures and how many signers
    a version takes. Everything goes through the API. What a card offers (cancel, send again, delete) comes with each version
    (its "abilities"), not from the role. The list asks for each version that is still on its way every two seconds.

    The files are plain links to the addresses the API gives: they carry no rel="noreferrer", because the API recognises the web
    front by its Referer. The live region is on the page from the start: a region that is added together with its message is not
    reliably announced.

    Do not put a display utility (d-flex...) on an element that carries :hidden: the utility wins and it never hides.
    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', __('Official versions').' · '.$inventory->name)

@section('content')
    <div
        x-data="inventoryVersions"
        data-url="{{ route('api.v1.inventories.versions.index', ['inventory' => $inventory->ulid], absolute: false) }}"
        data-version-url="{{ route('api.v1.inventories.versions.show', ['inventory' => $inventory->ulid, 'version' => '__id__'], absolute: false) }}"
        data-cancel-url="{{ route('api.v1.inventories.versions.signature.cancel', ['inventory' => $inventory->ulid, 'version' => '__id__'], absolute: false) }}"
        data-retry-url="{{ route('api.v1.inventories.versions.signature.retry', ['inventory' => $inventory->ulid, 'version' => '__id__'], absolute: false) }}"
        data-can-create="{{ $abilities['create'] ? '1' : '0' }}"
        data-signatures-available="{{ $signaturesAvailable ? '1' : '0' }}"
        data-max-signers="{{ $maxSigners }}"
    >
        <nav aria-label="{{ __('Breadcrumb') }}">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('app.workspace', ['workspace' => $workspace->ulid], absolute: false) }}">{{ $workspace->name }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('app.inventory', ['workspace' => $workspace->ulid, 'inventory' => $inventory->ulid], absolute: false) }}">{{ $inventory->name }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ __('Official versions') }}</li>
            </ol>
        </nav>

        <div class="d-flex flex-wrap align-items-start gap-3 mb-3">
            <div class="me-auto">
                <h1 class="page-title" x-ref="title" tabindex="-1">{{ __('Official versions') }}</h1>
                <p class="text-secondary mb-0 mt-1">
                    {{ __('A version freezes the inventory as it is now: its data, a PDF, and a SHA-256 fingerprint that shows nothing changed since. People can be asked to sign the PDF.') }}
                </p>
            </div>
            <div class="btn-list">
                <a class="btn" href="{{ route('app.inventory', ['workspace' => $workspace->ulid, 'inventory' => $inventory->ulid], absolute: false) }}">
                    <x-tabler-table aria-hidden="true" />
                    {{ __('Back to the items') }}
                </a>
                <button type="button" class="btn btn-primary" :hidden="!canCreate" @click="startCreate">
                    <x-tabler-plus aria-hidden="true" />
                    {{ __('New version') }}
                </button>
            </div>
        </div>

        <div class="visually-hidden" role="status" aria-live="polite" aria-atomic="true" x-text="status"></div>

        {{-- Hidden with the "hidden" attribute, not x-show: Alpine shows an element a tick late and hides it at once, so a
             state that goes up and down faster than that (a local server answers in a few milliseconds) would leave it on. --}}
        <div class="card" :hidden="!loading">
            <div class="card-body text-secondary" role="status">
                <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                {{ __('Loading…') }}
            </div>
        </div>

        {{-- The versions could not be read: the inventory is not known to have none, so the page says so and offers to try again. --}}
        <div class="alert alert-danger" role="alert" :hidden="!loadFailed">
            <div class="d-flex align-items-center gap-3">
                <span>{{ __('The versions could not be loaded.') }}</span>
                <button type="button" class="btn btn-sm ms-auto" @click="load" :disabled="loading">{{ __('Try again') }}</button>
            </div>
        </div>

        {{-- The versions on their way could not be followed: what is listed may be out of date. --}}
        <div class="alert alert-warning" :hidden="!pollFailed">
            <div class="d-flex align-items-center gap-3">
                <span>{{ __('The progress of the versions could not be refreshed.') }}</span>
                <button type="button" class="btn btn-sm ms-auto" @click="retryPolling">{{ __('Try again') }}</button>
            </div>
        </div>

        <div class="card" :hidden="!empty">
            <div class="card-body">
                <div class="empty">
                    <div class="empty-icon hero-icon text-primary">
                        <x-tabler-certificate aria-hidden="true" />
                    </div>
                    <p class="empty-title">{{ __('No official version yet') }}</p>
                    <p class="empty-subtitle text-secondary">
                        {{ __('Freeze the inventory to keep its state at a given date: year-end inventory, handover of equipment, check-in report...') }}
                    </p>
                    <div class="empty-action" :hidden="!canCreate">
                        <button type="button" class="btn btn-primary" @click="startCreate">
                            <x-tabler-plus aria-hidden="true" />
                            {{ __('New version') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div :hidden="!hasVersions">
            <template x-for="row in rows" :key="row.id">
                <article class="card mb-3" :aria-labelledby="row.titleId">
                    <div class="card-body">
                        <div class="row g-2 align-items-start">
                            <div class="col-12 col-md">
                                <h2 class="h3 mb-1 text-break" :id="row.titleId" x-text="row.title"></h2>
                                <div class="fw-medium text-break" :hidden="row.label === ''" x-text="row.label"></div>
                                <div class="text-secondary small" x-text="row.details"></div>
                            </div>
                            <div class="col-12 col-md-auto">
                                <span class="badge" :class="row.badgeClass" x-text="row.statusLabel"></span>
                            </div>
                        </div>

                        <div class="text-danger small mt-2 text-break" :hidden="row.error === ''" x-text="row.error"></div>

                        <div class="small text-secondary mt-2">
                            <span>{{ __('SHA-256 of the data:') }}</span>
                            <code class="text-body text-break" :title="row.sha256" x-text="row.shortHash"></code>
                            <button type="button" class="btn btn-sm btn-icon btn-ghost-secondary" :aria-label="row.hashLabel" :title="row.hashLabel" @click="copyHash(row)">
                                <x-tabler-copy aria-hidden="true" />
                            </button>
                        </div>

                        <div class="mt-3 pt-3 border-top" :hidden="!row.hasSignature">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <h3 class="h4 m-0">{{ __('Signatures') }}</h3>
                                <span class="badge" :class="row.signatureBadgeClass" x-text="row.signatureLabel"></span>
                                <span class="text-secondary small" :hidden="row.signatureSummary === ''" x-text="row.signatureSummary"></span>
                            </div>
                            <div class="text-secondary small mt-1" :hidden="row.signatureDates === ''" x-text="row.signatureDates"></div>
                            <div class="text-danger small mt-1 text-break" :hidden="row.signatureError === ''" x-text="row.signatureError"></div>
                            <ul class="list-group list-group-flush mt-2">
                                <template x-for="signer in row.signers" :key="signer.key">
                                    <li class="list-group-item px-0">
                                        <div class="row g-2 align-items-center">
                                            <div class="col-12 col-sm text-break">
                                                <div class="fw-medium" x-text="signer.name"></div>
                                                <div class="text-secondary small" x-text="signer.email"></div>
                                                <div class="small" :hidden="signer.detail === ''" x-text="signer.detail"></div>
                                            </div>
                                            <div class="col-12 col-sm-auto">
                                                <span class="badge" :class="signer.badgeClass" x-text="signer.statusLabel"></span>
                                            </div>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </div>

                        <div class="btn-list mt-3">
                            <a class="btn btn-sm btn-primary" download :href="row.pdfUrl" :hidden="row.pdfUrl === null" :aria-label="row.pdfLabel">
                                <x-tabler-file-type-pdf aria-hidden="true" />
                                {{ __('PDF') }}
                            </a>
                            <a class="btn btn-sm" download :href="row.dataUrl" :hidden="row.dataUrl === null" :aria-label="row.dataLabel">
                                <x-tabler-braces aria-hidden="true" />
                                {{ __('Data (JSON)') }}
                            </a>
                            <a class="btn btn-sm btn-primary" download :href="row.signedUrl" :hidden="row.signedUrl === null" :aria-label="row.signedLabel">
                                <x-tabler-signature aria-hidden="true" />
                                {{ __('Signed PDF') }}
                            </a>
                            <button type="button" class="btn btn-sm" :hidden="!row.canCancel" :disabled="row.working" :aria-label="row.cancelLabel" @click="askCancel(row)">
                                <x-tabler-x aria-hidden="true" />
                                {{ __('Cancel the request') }}
                            </button>
                            <button type="button" class="btn btn-sm" :hidden="!row.canRetry" :disabled="row.working" :aria-label="row.retryLabel" @click="retryRequest(row)">
                                <x-tabler-send aria-hidden="true" />
                                {{ __('Send again') }}
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" :hidden="!row.canDelete" :disabled="row.working" :aria-label="row.deleteLabel" @click="askDelete(row)">
                                <x-tabler-trash aria-hidden="true" />
                                {{ __('Delete') }}
                            </button>
                        </div>
                    </div>
                </article>
            </template>

            <div class="text-center text-secondary">
                <p class="mb-2" :hidden="!partial" x-text="summary"></p>
                <button type="button" class="btn" :hidden="!hasMore" :disabled="loadingMore" :aria-busy="loadingMore" @click="loadMore">
                    <span class="spinner-border spinner-border-sm me-2" :hidden="!loadingMore" aria-hidden="true"></span>
                    {{ __('Show more') }}
                </button>
            </div>
        </div>

        <div class="modal modal-blur fade" tabindex="-1" x-ref="createModal" role="dialog" aria-labelledby="version-create-title" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
                <form class="modal-content" @submit.prevent="submit" novalidate>
                    <div class="modal-header">
                        <h2 class="modal-title" id="version-create-title">{{ __('New official version') }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-secondary">
                            {{ __('The inventory is frozen as it is at this moment. Items changed afterwards do not alter the version.') }}
                        </p>

                        <div class="mb-3">
                            <label class="form-label" for="version-label">{{ __('Label (optional)') }}</label>
                            <input
                                id="version-label"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': errors.label }"
                                x-model="form.label"
                                :maxlength="maxLabelLength"
                                aria-describedby="version-label-hint"
                                autocomplete="off"
                                data-autofocus
                            >
                            <div class="invalid-feedback" x-text="errors.label"></div>
                            <div class="form-hint" id="version-label-hint">{{ __('For example “Year-end inventory 2026”.') }}</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-check form-switch mb-1">
                                <input
                                    id="version-sign"
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    :checked="form.sign"
                                    :disabled="!signaturesAvailable"
                                    aria-describedby="version-sign-hint"
                                    @change="toggleSign"
                                >
                                <span class="form-check-label">{{ __('Ask for signatures') }}</span>
                            </label>
                            <div class="form-hint" id="version-sign-hint">
                                <span :hidden="signaturesAvailable">{{ __('Signatures are not available: this instance has no signature service. An administrator can set up Documenso in the environment of the instance.') }}</span>
                                <span :hidden="!signaturesAvailable">{{ __('Each signer receives an email with a link to sign the PDF of the version. The signers cannot be changed afterwards: freeze a new version to ask somebody else.') }}</span>
                            </div>
                        </div>

                        <fieldset :hidden="!form.sign">
                            <legend class="form-label">{{ __('Signers') }}</legend>
                            <div class="form-hint mb-2" x-text="signersHint"></div>
                            <div class="text-danger small mb-2" :hidden="!errors.signers" x-text="errors.signers"></div>

                            <template x-for="(signer, index) in form.signers" :key="signer.key">
                                <div class="row g-2 align-items-start mb-2">
                                    <div class="col-12 col-sm">
                                        <label class="form-label" :for="signer.nameId">{{ __('Name') }}</label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            :id="signer.nameId"
                                            :class="{ 'is-invalid': signerError(index, 'name') }"
                                            :aria-label="signerLabel(index, 'name')"
                                            x-model="signer.name"
                                            autocomplete="off"
                                        >
                                        <div class="invalid-feedback" x-text="signerError(index, 'name')"></div>
                                    </div>
                                    <div class="col-12 col-sm">
                                        <label class="form-label" :for="signer.emailId">{{ __('Email address') }}</label>
                                        <input
                                            type="email"
                                            class="form-control"
                                            :id="signer.emailId"
                                            :class="{ 'is-invalid': signerError(index, 'email') }"
                                            :aria-label="signerLabel(index, 'email')"
                                            x-model="signer.email"
                                            autocomplete="off"
                                        >
                                        <div class="invalid-feedback" x-text="signerError(index, 'email')"></div>
                                    </div>
                                    <div class="col-12 col-sm-auto">
                                        <span class="form-label d-none d-sm-block" aria-hidden="true">&nbsp;</span>
                                        <button type="button" class="btn btn-outline-secondary" :aria-label="signerLabel(index, 'remove')" :disabled="!canRemoveSigner" @click="removeSigner(index)">
                                            <x-tabler-trash aria-hidden="true" />
                                            {{ __('Remove') }}
                                        </button>
                                    </div>
                                </div>
                            </template>

                            <button type="button" class="btn" :disabled="!canAddSigner" @click="addSigner">
                                <x-tabler-plus aria-hidden="true" />
                                {{ __('Add a signer') }}
                            </button>
                        </fieldset>
                    </div>
                    <div class="modal-footer">
                        <div class="alert alert-danger w-100 mb-0 text-break" role="alert" :hidden="formError === ''" x-text="formError"></div>
                        <button type="button" class="btn me-auto" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy">
                            <span class="spinner-border spinner-border-sm me-2" :hidden="!busy" aria-hidden="true"></span>
                            {{ __('Create the version') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal modal-blur fade" tabindex="-1" x-ref="confirmModal" role="dialog" aria-labelledby="version-confirm-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="version-confirm-title" x-text="confirmTitle"></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <p class="m-0 text-break" x-text="confirmText"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn me-auto" data-bs-dismiss="modal">{{ __('Close') }}</button>
                        <button type="button" class="btn btn-danger" @click="confirmAction" x-text="confirmButton"></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
