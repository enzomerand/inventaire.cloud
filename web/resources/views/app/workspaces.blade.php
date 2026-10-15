{{--
    The workspaces page: the workspaces of the signed-in user, to create, rename, delete and choose between.

    Alpine runs in its CSP build: the "workspaces" component (resources/js/components/workspaces.js) is referenced by
    name and gets its endpoints from the data-* attributes of its root. The list itself is the shared "workspaces"
    store, which the switcher of the header fills from the list the server rendered. Everything goes through the API;
    deleting asks for a confirmation in the row.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', __('Workspaces'))

@section('heading', __('Workspaces'))

@section('content')
    <div
        class="row row-cards"
        x-data="workspaces"
        data-url="{{ route('api.v1.workspaces.index', absolute: false) }}"
        data-current-url="{{ route('api.v1.me.current-workspace', absolute: false) }}"
    >
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('Your workspaces') }}</h2>
                </div>
                <div class="list-group list-group-flush">
                    <template x-for="item in items" :key="item.id">
                        <div class="list-group-item">
                            <div class="row align-items-center g-2">
                                <div class="col-12 col-md">
                                    <form class="d-flex gap-2" x-show="isEditing(item)" @submit.prevent="saveRename(item)" novalidate>
                                        <label class="visually-hidden" :for="'workspace-name-' + item.id">{{ __('Name') }}</label>
                                        <input
                                            :id="'workspace-name-' + item.id"
                                            type="text"
                                            class="form-control"
                                            x-model="editName"
                                            maxlength="100"
                                            autocomplete="off"
                                        >
                                        <button type="submit" class="btn btn-primary" :disabled="busy">{{ __('Save') }}</button>
                                        <button type="button" class="btn" @click="cancelRename" :disabled="busy">{{ __('Cancel') }}</button>
                                    </form>

                                    <div class="d-flex flex-wrap align-items-center gap-2" x-show="!isEditing(item)">
                                        <span class="fw-bold text-break" x-text="item.name"></span>
                                        <span class="badge bg-primary-lt" x-show="item.current">{{ __('Current') }}</span>
                                        <span class="badge" x-text="roleLabel(item)"></span>
                                    </div>
                                </div>

                                <div class="col-12 col-md-auto">
                                    <div class="d-flex flex-wrap align-items-center gap-2" x-show="isConfirming(item)">
                                        <span class="text-danger" x-text="confirmText(item)"></span>
                                        <button type="button" class="btn btn-danger" @click="confirmDelete(item)" :disabled="busy">{{ __('Delete') }}</button>
                                        <button type="button" class="btn" @click="cancelDelete" :disabled="busy">{{ __('Cancel') }}</button>
                                    </div>

                                    <div class="d-flex flex-wrap gap-2" x-show="!isConfirming(item) && !isEditing(item)">
                                        <button type="button" class="btn btn-sm" x-show="!item.current" @click="makeCurrent(item)" :disabled="busy">
                                            {{ __('Work in this workspace') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-icon"
                                            x-show="canManage(item)"
                                            :aria-label="renameLabel(item)"
                                            :title="renameLabel(item)"
                                            @click="startRename(item)"
                                            :disabled="busy"
                                        >
                                            <x-tabler-pencil aria-hidden="true" />
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-icon btn-outline-danger"
                                            x-show="canManage(item)"
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
                    </template>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card" id="create">
                <div class="card-header">
                    <h2 class="card-title">{{ __('New workspace') }}</h2>
                </div>
                <form class="card-body" @submit.prevent="create" novalidate>
                    <p class="text-secondary">{{ __('A workspace keeps its own inventories. You own the ones you create.') }}</p>
                    <div class="mb-3">
                        <label class="form-label" for="workspace-new-name">{{ __('Name') }}</label>
                        <input
                            id="workspace-new-name"
                            type="text"
                            class="form-control"
                            x-model="newName"
                            x-ref="name"
                            maxlength="100"
                            autocomplete="off"
                            required
                        >
                    </div>
                    <button type="submit" class="btn btn-primary w-100" :disabled="!canCreate">
                        <x-tabler-plus aria-hidden="true" />
                        {{ __('Create the workspace') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
