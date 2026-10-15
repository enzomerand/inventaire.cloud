{{--
    The page of an inventory: its name and description with what may be done with it, and its items in a table with one
    column per field, plus the dialog that adds, changes, shows or deletes an item.

    Alpine runs in its CSP build: two components share the page, each referenced by name and fed by the data-* attributes
    of its root. "inventoryHeader" (resources/js/components/inventory-header.js) owns the name, the description and their
    dialogs; "inventoryItems" (resources/js/components/inventory-items.js) owns the table and the item dialog. In the
    URL templates, __id__ stands for the ULID of the item. Everything goes through the API; the form of an item is
    generated from the fields of the inventory. Values are shown by partials/field-value (x-text, never x-html).

    Do not put a display utility (d-flex...) on an element that carries :hidden: the utility wins and it never hides.
    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', $inventory->name)

@section('content')
    <div
        x-data="inventoryHeader"
        data-url="{{ route('api.v1.inventories.show', ['inventory' => $inventory->ulid], absolute: false) }}"
        data-workspace-page="{{ route('app.workspace', ['workspace' => $workspace->ulid], absolute: false) }}"
        data-name="{{ $inventory->name }}"
        data-description="{{ $inventory->description }}"
        data-can-update="{{ $abilities['update'] ? '1' : '0' }}"
        data-can-delete="{{ $abilities['delete'] ? '1' : '0' }}"
        data-can-manage-fields="{{ $abilities['manage_fields'] ? '1' : '0' }}"
    >
        <nav aria-label="{{ __('Breadcrumb') }}">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('app.workspace', ['workspace' => $workspace->ulid], absolute: false) }}">{{ $workspace->name }}</a></li>
                <li class="breadcrumb-item active" aria-current="page"><span x-text="name">{{ $inventory->name }}</span></li>
            </ol>
        </nav>

        <div class="d-flex flex-wrap align-items-start gap-3 mb-3">
            <div class="me-auto">
                <h1 class="page-title text-break" x-text="name">{{ $inventory->name }}</h1>
                <p class="text-secondary text-break app-multiline mb-0 mt-1" :hidden="!hasDescription" x-text="description">{{ $inventory->description }}</p>
            </div>
            <div class="btn-list">
                <a class="btn" x-show="canManageFields" href="{{ route('app.inventory.fields', ['workspace' => $workspace->ulid, 'inventory' => $inventory->ulid], absolute: false) }}">
                    <x-tabler-layout-columns aria-hidden="true" />
                    {{ __('Fields') }}
                </a>
                <button type="button" class="btn" x-show="canUpdate" @click="startEdit" :disabled="busy">
                    <x-tabler-pencil aria-hidden="true" />
                    {{ __('Edit') }}
                </button>
                <button type="button" class="btn btn-outline-danger" x-show="canDelete" @click="askDelete" :disabled="busy">
                    <x-tabler-trash aria-hidden="true" />
                    {{ __('Delete') }}
                </button>
            </div>
        </div>

        <div class="modal modal-blur fade" tabindex="-1" x-ref="editModal" role="dialog" aria-labelledby="inventory-edit-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
                <form class="modal-content" @submit.prevent="save" novalidate>
                    <div class="modal-header">
                        <h2 class="modal-title" id="inventory-edit-title">{{ __('Edit the inventory') }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger" role="alert" x-show="formError" x-text="formError"></div>

                        <div class="mb-3">
                            <label class="form-label required" for="inventory-edit-name">{{ __('Name') }}</label>
                            <input
                                id="inventory-edit-name"
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
                            <label class="form-label" for="inventory-edit-description">{{ __('Description') }}</label>
                            <textarea
                                id="inventory-edit-description"
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
                        <button type="submit" class="btn btn-primary" :disabled="!canSubmit">{{ __('Save') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal modal-blur fade" tabindex="-1" x-ref="deleteModal" role="dialog" aria-labelledby="inventory-delete-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="inventory-delete-title">{{ __('Delete the inventory') }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <p class="m-0 text-break" x-text="confirmText"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn me-auto" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="button" class="btn btn-danger" @click="confirmDelete" :disabled="busy">{{ __('Delete') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div
        x-data="inventoryItems"
        data-url="{{ route('api.v1.inventories.show', ['inventory' => $inventory->ulid], absolute: false) }}"
        data-items-url="{{ route('api.v1.inventories.items.index', ['inventory' => $inventory->ulid], absolute: false) }}"
        data-item-url="{{ route('api.v1.inventories.items.show', ['inventory' => $inventory->ulid, 'item' => '__id__'], absolute: false) }}"
        data-can-edit="{{ $abilities['edit_items'] ? '1' : '0' }}"
        data-can-manage-fields="{{ $abilities['manage_fields'] ? '1' : '0' }}"
    >
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('Items') }}</h2>
                <div class="card-actions">
                    <button type="button" class="btn btn-primary" x-show="canAdd" @click="startCreate">
                        <x-tabler-plus aria-hidden="true" />
                        {{ __('Add item') }}
                    </button>
                </div>
            </div>

            {{-- Hidden with the "hidden" attribute, not x-show: Alpine shows an element a tick late and hides it at once, so a
                 state that goes up and down faster than that (a local server answers in a few milliseconds) would leave it on. --}}
            <div class="card-body text-secondary" :hidden="!loading" role="status">
                <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                {{ __('Loading…') }}
            </div>

            {{-- Something could not be read: the inventory is not known to have no field or no item, so the page says so and offers
                 to try again. --}}
            <div class="card-body" :hidden="!loadFailed">
                <div class="alert alert-danger m-0" role="alert">
                    <div class="d-flex align-items-center gap-3">
                        <span>{{ __('The items could not be loaded.') }}</span>
                        <button type="button" class="btn btn-sm ms-auto" @click="retry" :disabled="loading">{{ __('Try again') }}</button>
                    </div>
                </div>
            </div>

            <div class="card-body" :hidden="!noFields">
                <div class="empty">
                    <div class="empty-icon hero-icon text-primary">
                        <x-tabler-layout-columns aria-hidden="true" />
                    </div>
                    <p class="empty-title">{{ __('This inventory has no field yet') }}</p>
                    <p class="empty-subtitle text-secondary">
                        {{ __('Fields describe what each item holds: a name, a quantity, a price, a photo... Add them first, then the items.') }}
                    </p>
                    <div class="empty-action" x-show="canManageFields">
                        <a class="btn btn-primary" href="{{ route('app.inventory.fields', ['workspace' => $workspace->ulid, 'inventory' => $inventory->ulid], absolute: false) }}">
                            <x-tabler-plus aria-hidden="true" />
                            {{ __('Add fields') }}
                        </a>
                    </div>
                </div>
            </div>

            <div class="card-body" :hidden="!noItems">
                <div class="empty">
                    <div class="empty-icon hero-icon text-primary">
                        <x-tabler-packages aria-hidden="true" />
                    </div>
                    <p class="empty-title">{{ __('No item yet') }}</p>
                    <p class="empty-subtitle text-secondary">{{ __('The items you add appear here, newest first.') }}</p>
                    <div class="empty-action" x-show="canAdd">
                        <button type="button" class="btn btn-primary" @click="startCreate">
                            <x-tabler-plus aria-hidden="true" />
                            {{ __('Add item') }}
                        </button>
                    </div>
                </div>
            </div>

            {{-- position-relative: the visually hidden label of the last header is absolutely positioned, and without a positioned
                 ancestor it would sit outside the scrolling area, making the whole page scroll sideways on a phone. --}}
            <div class="table-responsive position-relative" :hidden="!hasTable">
                <table class="table table-vcenter card-table table-hover">
                    <thead>
                        <tr>
                            <template x-for="field in fields" :key="field.id">
                                <th class="text-nowrap" x-text="field.name"></th>
                            </template>
                            <th class="w-1"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in rows" :key="row.id">
                            <tr class="app-row-link" @click="openFromRow(row, $event)">
                                <template x-for="cell in row.cells" :key="cell.key">
                                    <td class="app-cell">
                                        @include('partials.field-value')
                                    </td>
                                </template>
                                <td class="w-1">
                                    <button type="button" class="btn btn-sm btn-icon" :aria-label="openLabel" :title="openLabel" @click.stop="open(row)">
                                        <x-tabler-pencil aria-hidden="true" />
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="card-footer" :hidden="!hasTable">
                <div class="d-flex align-items-center gap-2">
                    <p class="m-0 text-secondary" x-text="rangeLabel"></p>
                    <div class="btn-list ms-auto">
                        <button type="button" class="btn btn-sm" @click="previous" :disabled="!hasPrevious || loadingItems">{{ __('Previous') }}</button>
                        <button type="button" class="btn btn-sm" @click="next" :disabled="!hasNext || loadingItems">{{ __('Next') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal modal-blur fade" tabindex="-1" x-ref="modal" role="dialog" aria-labelledby="item-modal-title" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
                <form class="modal-content" @submit.prevent="save" novalidate>
                    <div class="modal-header">
                        <h2 class="modal-title" id="item-modal-title" x-text="modalTitle"></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger" role="alert" x-show="formError" x-text="formError"></div>

                        <template x-if="confirming">
                            <p class="m-0">{{ __('Delete this item? This cannot be undone.') }}</p>
                        </template>

                        <template x-if="isForm && !confirming">
                            <div>
                                <template x-for="field in fields" :key="field.id">
                                    <div class="mb-3">
                                        <label class="form-label" :class="{ required: field.required }" :for="controlId(field)" x-text="field.name"></label>

                                        <template x-if="control(field) === 'input'">
                                            <input
                                                :id="controlId(field)"
                                                :type="inputType(field)"
                                                :inputmode="inputMode(field)"
                                                :maxlength="maxLength(field)"
                                                :class="controlClass(field)"
                                                :value="inputValue(field)"
                                                @input="onInput(field, $event)"
                                                autocomplete="off"
                                            >
                                        </template>

                                        <template x-if="control(field) === 'textarea'">
                                            <textarea
                                                :id="controlId(field)"
                                                :class="controlClass(field)"
                                                :value="inputValue(field)"
                                                @input="onInput(field, $event)"
                                                maxlength="10000"
                                                rows="4"
                                            ></textarea>
                                        </template>

                                        <template x-if="control(field) === 'select'">
                                            <select :id="controlId(field)" :class="selectClass(field)" @change="onInput(field, $event)">
                                                <template x-for="option in selectOptions(field)" :key="option.value">
                                                    <option :value="option.value" :selected="isSelected(field, option.value)" x-text="option.label"></option>
                                                </template>
                                            </select>
                                        </template>

                                        <template x-if="control(field) === 'checks'">
                                            <div role="group" :aria-label="field.name">
                                                <template x-for="choice in choices(field)" :key="choice.id">
                                                    <label class="form-check">
                                                        <input class="form-check-input" type="checkbox" :checked="isChosen(field, choice.id)" @change="toggleChoice(field, choice.id, $event)">
                                                        <span class="form-check-label" x-text="choice.label"></span>
                                                    </label>
                                                </template>
                                            </div>
                                        </template>

                                        <template x-if="control(field) === 'tags'">
                                            <div>
                                                <div class="d-flex flex-wrap gap-1 mb-2">
                                                    <template x-for="tag in tagsOf(field)" :key="tag">
                                                        <span class="badge bg-secondary-lt d-inline-flex align-items-center gap-1">
                                                            <span x-text="tag"></span>
                                                            <button type="button" class="btn-close" :aria-label="removeTagLabel(tag)" @click="removeTag(field, tag)"></button>
                                                        </span>
                                                    </template>
                                                </div>
                                                <input
                                                    :id="controlId(field)"
                                                    type="text"
                                                    class="form-control"
                                                    maxlength="50"
                                                    autocomplete="off"
                                                    placeholder="{{ __('Type a tag, then press Enter') }}"
                                                    :value="draftOf(field)"
                                                    @input="onDraft(field, $event)"
                                                    @keydown.enter.prevent="commitTag(field)"
                                                    @keydown.comma.prevent="commitTag(field)"
                                                    @blur="commitTag(field)"
                                                >
                                            </div>
                                        </template>

                                        <template x-if="control(field) === 'rating'">
                                            <div class="d-flex gap-1" role="group" :aria-label="field.name">
                                                <template x-for="grade in scale(field)" :key="grade">
                                                    <button
                                                        type="button"
                                                        :class="starClass(field, grade)"
                                                        :aria-pressed="isRated(field, grade) ? 'true' : 'false'"
                                                        :aria-label="starLabel(field, grade)"
                                                        @click="setRating(field, grade)"
                                                        x-text="starGlyph(field, grade)"
                                                    ></button>
                                                </template>
                                            </div>
                                        </template>

                                        <template x-if="control(field) === 'color'">
                                            <div class="d-flex align-items-center gap-2">
                                                <input
                                                    :id="controlId(field)"
                                                    type="color"
                                                    class="form-control form-control-color"
                                                    :value="colorValue(field)"
                                                    @input="onInput(field, $event)"
                                                >
                                                <span class="font-monospace text-secondary" x-text="inputValue(field)"></span>
                                                <button type="button" class="btn btn-sm" x-show="hasText(field)" @click="clearValue(field)">{{ __('Clear') }}</button>
                                            </div>
                                        </template>

                                        <div class="text-danger small mt-1" x-show="hasError(field)" x-text="errorOf(field)"></div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <template x-if="!isForm">
                            <dl class="m-0">
                                <template x-for="cell in details" :key="cell.key">
                                    <div class="mb-3">
                                        <dt class="text-secondary fw-normal small" x-text="cell.name"></dt>
                                        <dd class="m-0">
                                            @include('partials.field-value')
                                        </dd>
                                    </div>
                                </template>
                            </dl>
                        </template>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-danger me-auto" x-show="canDeleteItem" @click="askDelete" :disabled="busy">{{ __('Delete') }}</button>
                        <button type="button" class="btn" x-show="isForm && !confirming" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="button" class="btn" x-show="!isForm" data-bs-dismiss="modal">{{ __('Close') }}</button>
                        <button type="submit" class="btn btn-primary" x-show="isForm && !confirming" :disabled="busy">{{ __('Save') }}</button>
                        <button type="button" class="btn" x-show="confirming" @click="cancelDelete" :disabled="busy">{{ __('Cancel') }}</button>
                        <button type="button" class="btn btn-danger" x-show="confirming" @click="confirmDelete" :disabled="busy">{{ __('Delete the item') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
