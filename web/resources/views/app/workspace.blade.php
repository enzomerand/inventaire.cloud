{{--
    The page of a workspace: its inventories as cards, and the dialog that adds one (editors and owners).

    Alpine runs in its CSP build: the "workspaceInventories" component (resources/js/components/workspace-inventories.js)
    is referenced by name and gets its endpoint, the link of a card (__id__ stands for the ULID of the inventory) and
    what the person may do from the data-* attributes of its root. The list is read from the API. The dialog is Tabler's
    (Bootstrap) modal, full screen on a phone.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', $workspace->name)

@section('heading', $workspace->name)

@section('content')
    <div
        x-data="workspaceInventories"
        data-url="{{ route('api.v1.workspaces.inventories.index', ['workspace' => $workspace->ulid], absolute: false) }}"
        data-inventory-url="{{ route('app.inventory', ['workspace' => $workspace->ulid, 'inventory' => '__id__'], absolute: false) }}"
        data-can-create="{{ $abilities['create'] ? '1' : '0' }}"
    >
        <div class="d-flex align-items-center mb-3">
            <h2 class="h3 m-0">{{ __('Inventories') }}</h2>
            <button type="button" class="btn btn-primary ms-auto" x-show="canCreate" @click="startCreate">
                <x-tabler-plus aria-hidden="true" />
                {{ __('New inventory') }}
            </button>
        </div>

        {{-- Hidden with the "hidden" attribute, not x-show: Alpine shows an element a tick late and hides it at once, so a
             state that goes up and down faster than that (a local server answers in a few milliseconds) would leave it on. --}}
        <p class="text-secondary" :hidden="!loading" role="status">
            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
            {{ __('Loading…') }}
        </p>

        <div class="row row-cards">
            <template x-for="item in items" :key="item.id">
                <div class="col-12 col-md-6 col-lg-4">
                    <a class="card card-link card-link-pop h-100 text-reset" :href="pageUrl(item)">
                        <div class="card-body">
                            <h3 class="card-title text-break" x-text="item.name"></h3>
                            <p class="text-secondary text-break app-multiline app-clamp" x-text="item.description" :hidden="!item.description"></p>
                            <div class="text-secondary small" x-text="countsLabel(item)"></div>
                        </div>
                    </a>
                </div>
            </template>
        </div>

        <div class="card" :hidden="!empty">
            <div class="card-body">
                <div class="empty">
                    <div class="empty-icon hero-icon text-primary">
                        <x-tabler-packages aria-hidden="true" />
                    </div>
                    <p class="empty-title">{{ __('No inventory yet') }}</p>
                    <p class="empty-subtitle text-secondary">
                        {{ __('An inventory is a table of items that share the same fields: tools, books, stock...') }}
                    </p>
                    <div class="empty-action" x-show="canCreate">
                        <button type="button" class="btn btn-primary" @click="startCreate">
                            <x-tabler-plus aria-hidden="true" />
                            {{ __('New inventory') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal modal-blur fade" tabindex="-1" x-ref="modal" role="dialog" aria-labelledby="inventory-create-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
                <form class="modal-content" @submit.prevent="create" novalidate>
                    <div class="modal-header">
                        <h2 class="modal-title" id="inventory-create-title">{{ __('New inventory') }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger" role="alert" x-show="formError" x-text="formError"></div>

                        <div class="mb-3">
                            <label class="form-label required" for="inventory-create-name">{{ __('Name') }}</label>
                            <input
                                id="inventory-create-name"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': errors.name }"
                                x-model="form.name"
                                maxlength="120"
                                autocomplete="off"
                                data-autofocus
                                required
                            >
                            <div class="invalid-feedback" x-text="errors.name"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="inventory-create-description">{{ __('Description') }}</label>
                            <textarea
                                id="inventory-create-description"
                                class="form-control"
                                :class="{ 'is-invalid': errors.description }"
                                x-model="form.description"
                                maxlength="2000"
                                rows="3"
                            ></textarea>
                            <div class="invalid-feedback" x-text="errors.description"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn me-auto" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary" :disabled="!canSubmit">{{ __('Create the inventory') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
