{{--
    The workspaces page: the workspaces of the signed-in user, to create, rename, delete and choose between.

    Alpine runs in its CSP build: the "workspaces" component (resources/js/components/workspaces.js) is referenced by
    name and gets its endpoints from the data-* attributes of its root. The list itself is the shared "workspaces"
    store, which the switcher of the header fills from the list the server rendered. Everything goes through the API;
    deleting asks for a confirmation in the row. The name of a workspace links to its page (the inventories it holds).

    The photo entry: when the instance offers it ($photoEntry, see WorkspaceListPageController, is then the consent of each workspace
    the person owns by ULID, else null), the owner of a workspace gets a switch in its row. The list endpoint does not carry the consent
    (it is a token route), so the page reads it from data-photo-entry. Never put a display utility (d-flex...) on an element that carries
    x-show: the utility wins and it never hides.

    On an instance that sells plans, the switch of a workspace on a plan without the photo entry (Free) is shown off and disabled, with a
    hint that leads to the subscription page (data-billing-url, __id__ for the ULID). The page is given the ULIDs of the workspaces whose
    plan includes the feature ($photoEntryUnlocked, from data-photo-entry-unlocked; null when no plan is sold), so that a workspace created
    on the page afterwards, which is on Free, is locked too.

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
        data-workspace-url="{{ route('app.workspace', ['workspace' => '__id__'], absolute: false) }}"
        data-members-url="{{ route('app.workspace.members', ['workspace' => '__id__'], absolute: false) }}"
        data-photo-entry-url="{{ route('api.v1.workspaces.photo-entry.update', ['workspace' => '__id__'], absolute: false) }}"
        data-photo-entry="{{ json_encode($photoEntry, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR) }}"
        data-photo-entry-unlocked="{{ json_encode($photoEntryUnlocked, JSON_THROW_ON_ERROR) }}"
        data-live-subscriptions="{{ json_encode($liveSubscriptions ?? [], JSON_THROW_ON_ERROR) }}"
        data-billing-url="{{ route('app.workspace.billing', ['workspace' => '__id__'], absolute: false) }}"
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
                                    <form x-show="isEditing(item)" @submit.prevent="saveRename(item)" novalidate>
                                        <div class="d-flex gap-2">
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
                                        </div>
                                    </form>

                                    <div x-show="!isEditing(item)">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <a class="fw-bold text-break" :href="pageUrl(item)" x-text="item.name"></a>
                                            <span class="badge bg-primary-lt" x-show="item.current">{{ __('Current') }}</span>
                                            <span class="badge" x-text="roleLabel(item)"></span>
                                        </div>
                                        <div class="mt-2" x-show="canPhotoEntry(item)">
                                            <label class="form-check form-switch mb-0">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    role="switch"
                                                    :checked="photoEntryOn(item)"
                                                    :disabled="photoEntryDisabled(item)"
                                                    :aria-label="photoEntryLabel(item)"
                                                    :aria-describedby="photoEntryHintIds(item)"
                                                    @change="togglePhotoEntry(item, $event)"
                                                >
                                                <span class="form-check-label">{{ __('Photo entry (AI)') }}</span>
                                            </label>
                                            <div class="form-hint" :id="'workspace-photo-entry-hint-' + item.id">
                                                {{ __('Photos sent by the editors are read by Claude (Anthropic), then deleted.') }}
                                            </div>
                                            <div class="form-hint" :id="'workspace-photo-entry-plan-' + item.id" x-show="photoEntryLockedByPlan(item)">
                                                {{ __('The photo entry is part of the Pro plan.') }}
                                                <a :href="billingUrl(item)">{{ __('See the subscription') }}</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 col-md-auto">
                                    <div x-show="isConfirming(item)">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="text-danger" x-text="confirmText(item)"></span>
                                            <button type="button" class="btn btn-danger" @click="confirmDelete(item)" :disabled="busy">{{ __('Delete') }}</button>
                                            <button type="button" class="btn" @click="cancelDelete" :disabled="busy">{{ __('Cancel') }}</button>
                                        </div>
                                    </div>

                                    <div x-show="!isConfirming(item) && !isEditing(item)">
                                        <div class="d-flex flex-wrap gap-2">
                                            <button type="button" class="btn btn-sm" x-show="!item.current" @click="makeCurrent(item)" :disabled="busy">
                                                {{ __('Work in this workspace') }}
                                            </button>
                                            <a class="btn btn-sm btn-icon" :href="membersUrl(item)" :aria-label="membersLabel(item)" :title="membersLabel(item)">
                                                <x-tabler-users aria-hidden="true" />
                                            </a>
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
                                                x-show="canDelete(item)"
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
