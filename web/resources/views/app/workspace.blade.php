{{--
    The page of a workspace: its inventories as cards, the dialog that adds one (editors and owners) and the link to its members.

    Alpine runs in its CSP build: the "workspaceInventories" component (resources/js/components/workspace-inventories.js)
    is referenced by name and gets its endpoint, the link of a card (__id__ stands for the ULID of the inventory) and
    what the person may do from the data-* attributes of its root. The list is read from the API. The dialog is Tabler's
    (Bootstrap) modal, full screen on a phone.

    The dialog is a short path in one piece of markup, one block per step (data-step), of which only the current one is
    shown: the choice between an empty inventory and a template, the picker of templates, the preview of one, and the
    form. The control a step starts with carries data-autofocus (the component puts the cursor there when the step
    changes). The templates are read from the public API at the URLs of the root (data-templates-url, data-template-url
    with __id__ for the slug) when the person first asks for them. Every text that comes from the API goes through
    x-text, never x-html.

    The live regions (role="status") are always on the page and never hidden: a region that appears together with its text
    is not announced, so only their text changes (x-text, with the words of data-loading-label on the root). What people
    see while something loads is a separate spinner, which the screen readers skip (aria-hidden).

    The icon of a template is the same generic one for all: the API names a Tabler icon, but Blade cannot render a name
    that is only known in the browser.

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
        data-templates-url="{{ route('api.v1.templates.index', absolute: false) }}"
        data-template-url="{{ route('api.v1.templates.show', ['slug' => '__id__'], absolute: false) }}"
        data-loading-label="{{ __('Loading…') }}"
    >
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h2 class="h3 m-0">{{ __('Inventories') }}</h2>
            <a class="btn ms-auto" href="{{ route('app.workspace.members', ['workspace' => $workspace->ulid], absolute: false) }}">
                <x-tabler-users aria-hidden="true" />
                {{ __('Members') }}
            </a>
            <button type="button" class="btn btn-primary" x-ref="newInventory" x-show="canCreate" @click="startCreate">
                <x-tabler-plus aria-hidden="true" />
                {{ __('New inventory') }}
            </button>
        </div>

        {{-- Hidden with the "hidden" attribute, not x-show: Alpine shows an element a tick late and hides it at once, so a
             state that goes up and down faster than that (a local server answers in a few milliseconds) would leave it on. --}}
        <p class="text-secondary" :hidden="!loading" aria-hidden="true">
            <span class="spinner-border spinner-border-sm me-2"></span>
            {{ __('Loading…') }}
        </p>
        <div class="visually-hidden" role="status" x-text="loadingAnnouncement"></div>

        {{-- The list could not be read: the workspace is not empty for all we know, so it says so and offers to try again.
             The alert is hidden by attribute, with the flex layout on an inner element (a display utility would win). --}}
        <div class="alert alert-danger" role="alert" :hidden="!loadFailed">
            <div class="d-flex align-items-center gap-3">
                <span>{{ __('The inventories could not be loaded.') }}</span>
                <button type="button" class="btn btn-sm ms-auto" @click="load" :disabled="loading">{{ __('Try again') }}</button>
            </div>
        </div>

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
            <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down" :class="dialogClass">
                <form class="modal-content" @submit.prevent="submit" novalidate>
                    <div class="modal-header">
                        <h2 class="modal-title" id="inventory-create-title">{{ __('New inventory') }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger" role="alert" x-show="formError" x-text="formError"></div>

                        {{-- Step 1: an empty inventory, or a template. --}}
                        <div data-step="choose" :hidden="!onChoose">
                            <p class="text-secondary">{{ __('How do you want to start?') }}</p>
                            <div class="row g-3">
                                <div class="col-12 col-sm-6">
                                    <button
                                        type="button"
                                        class="card card-link h-100 app-choice"
                                        aria-labelledby="inventory-choice-scratch"
                                        aria-describedby="inventory-choice-scratch-hint"
                                        data-autofocus
                                        @click="startFromScratch"
                                    >
                                        <span class="card-body">
                                            <span class="avatar bg-primary-lt text-primary mb-3"><x-tabler-file-plus aria-hidden="true" /></span>
                                            <span class="d-block fw-bold" id="inventory-choice-scratch">{{ __('Start from scratch') }}</span>
                                            <span class="d-block text-secondary" id="inventory-choice-scratch-hint">{{ __('An empty inventory: you add its fields yourself.') }}</span>
                                        </span>
                                    </button>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <button
                                        type="button"
                                        class="card card-link h-100 app-choice"
                                        aria-labelledby="inventory-choice-template"
                                        aria-describedby="inventory-choice-template-hint"
                                        @click="startFromTemplate"
                                    >
                                        <span class="card-body">
                                            <span class="avatar bg-primary-lt text-primary mb-3"><x-tabler-template aria-hidden="true" /></span>
                                            <span class="d-block fw-bold" id="inventory-choice-template">{{ __('Start from a template') }}</span>
                                            <span class="d-block text-secondary" id="inventory-choice-template-hint">{{ __('Fields ready to use: home, shop, cellar, tools...') }}</span>
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- Step 2: the templates, narrowed by a search and by category. The list is read the first time this step opens. --}}
                        <div data-step="pick" :hidden="!onPick">
                            <div class="mb-3">
                                <label class="visually-hidden" for="inventory-template-search">{{ __('Search a template') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text"><x-tabler-search aria-hidden="true" /></span>
                                    <input
                                        id="inventory-template-search"
                                        type="search"
                                        class="form-control"
                                        x-model="templateSearch"
                                        maxlength="100"
                                        autocomplete="off"
                                        placeholder="{{ __('Search a template') }}"
                                        data-autofocus
                                        @keydown.enter.prevent
                                    >
                                </div>
                            </div>

                            <div class="mb-3" :hidden="!showCategories">
                                <div class="d-flex flex-wrap gap-2" role="group" aria-label="{{ __('Categories') }}">
                                    <template x-for="chip in categoryChips" :key="chip.category">
                                        <button
                                            type="button"
                                            class="btn btn-sm"
                                            :class="chipClass(chip)"
                                            :aria-pressed="chipPressed(chip)"
                                            @click="chooseCategory(chip)"
                                            x-text="chip.label"
                                        ></button>
                                    </template>
                                </div>
                            </div>

                            <p class="text-secondary" :hidden="!templatesLoading" aria-hidden="true">
                                <span class="spinner-border spinner-border-sm me-2"></span>
                                {{ __('Loading…') }}
                            </p>
                            <div class="visually-hidden" role="status" x-text="templatesLoadingAnnouncement"></div>

                            <div class="alert alert-danger" role="alert" :hidden="!templatesFailed">
                                <div class="d-flex align-items-center gap-3">
                                    <span>{{ __('The templates could not be loaded.') }}</span>
                                    <button type="button" class="btn btn-sm ms-auto" @click="loadTemplates" :disabled="templatesLoading">{{ __('Try again') }}</button>
                                </div>
                            </div>

                            <p class="text-secondary" :hidden="!templatesEmpty">{{ __('No template is available yet.') }}</p>
                            <p class="text-secondary" :hidden="!templatesNoMatch">{{ __('No template matches your search.') }}</p>

                            {{-- How many templates are left, for the people who cannot see the grid change. --}}
                            <div class="visually-hidden" role="status" aria-live="polite" x-text="templatesAnnouncement"></div>

                            <div class="row g-2">
                                <template x-for="entry in visibleTemplates" :key="entry.slug">
                                    <div class="col-12 col-md-6">
                                        <button type="button" class="card card-link h-100 app-choice" @click="selectTemplate(entry)">
                                            <span class="card-body d-flex align-items-start gap-3">
                                                <span class="avatar bg-primary-lt text-primary flex-shrink-0"><x-tabler-template aria-hidden="true" /></span>
                                                <span class="app-grow">
                                                    <span class="d-block fw-bold text-break" x-text="entry.name"></span>
                                                    <span class="text-secondary small text-break app-clamp" x-text="entry.summary"></span>
                                                    <span class="d-block text-secondary small mt-1" x-text="fieldsLabel(entry)"></span>
                                                </span>
                                            </span>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Step 3: what the template holds. --}}
                        <div data-step="preview" :hidden="!onPreview">
                            <h3 class="h2 mb-1 text-break" tabindex="-1" data-autofocus x-text="previewName"></h3>
                            <p class="mb-2" :hidden="!previewCategory"><span class="badge bg-blue-lt" x-text="previewCategory"></span></p>
                            <p class="text-break" x-text="previewSummary"></p>

                            <p class="text-secondary" :hidden="!previewLoading" aria-hidden="true">
                                <span class="spinner-border spinner-border-sm me-2"></span>
                                {{ __('Loading…') }}
                            </p>
                            <div class="visually-hidden" role="status" x-text="previewLoadingAnnouncement"></div>

                            <div class="alert alert-danger" role="alert" :hidden="!previewFailed">
                                <div class="d-flex align-items-center gap-3">
                                    <span>{{ __('This template could not be loaded.') }}</span>
                                    <button type="button" class="btn btn-sm ms-auto" @click="retryTemplate">{{ __('Try again') }}</button>
                                </div>
                            </div>

                            <div :hidden="!previewFieldCount">
                                <h4 class="h3">
                                    {{ __('Fields') }}
                                    <span class="badge bg-secondary-lt ms-1" x-text="previewFieldCount"></span>
                                </h4>
                                <ul class="list-group">
                                    <template x-for="field in previewFields" :key="field.key">
                                        <li class="list-group-item">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="app-grow text-break" x-text="field.name"></span>
                                                <span class="badge bg-red-lt flex-shrink-0" :hidden="!field.required">{{ __('Required') }}</span>
                                                <span class="badge bg-secondary-lt flex-shrink-0" x-text="field.type_label"></span>
                                            </div>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </div>

                        {{-- Step 4: the name and the description, which a template proposes. --}}
                        <div data-step="form" :hidden="!onForm">
                            <p class="text-secondary" :hidden="!fromTemplate" x-text="fromTemplateLabel"></p>

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
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn me-auto" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="button" class="btn" :hidden="onChoose" :disabled="busy" @click="back">{{ __('Back') }}</button>
                        <button type="button" class="btn btn-primary" :hidden="!onPreview" :disabled="!canUseTemplate" @click="useTemplate">{{ __('Use this template') }}</button>
                        <button type="submit" class="btn btn-primary" :hidden="!onForm" :disabled="!canSubmit">{{ __('Create the inventory') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
