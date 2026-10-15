{{--
    The field editor of an inventory (editors and owners): the fields in order, to add, change, delete and move.

    Alpine runs in its CSP build: the "inventoryFields" component (resources/js/components/inventory-fields.js) is
    referenced by name and fed by the data-* attributes of its root: the endpoints (__id__ stands for the ULID of a field),
    the types of field and the currencies with their names in the language of the page, as JSON. Everything goes through
    the API. The options each type has are shown by the type picked in the dialog.

    Do not put a display utility (d-flex...) on an element that carries :hidden: the utility wins and it never hides.
    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', __('Fields').' · '.$inventory->name)

@section('content')
    <div
        x-data="inventoryFields"
        data-url="{{ route('api.v1.inventories.show', ['inventory' => $inventory->ulid], absolute: false) }}"
        data-fields-url="{{ route('api.v1.inventories.fields.store', ['inventory' => $inventory->ulid], absolute: false) }}"
        data-workspace-page="{{ route('app.workspace', ['workspace' => $workspace->ulid], absolute: false) }}"
        data-order-url="{{ route('api.v1.inventories.fields.order', ['inventory' => $inventory->ulid], absolute: false) }}"
        data-field-url="{{ route('api.v1.inventories.fields.update', ['inventory' => $inventory->ulid, 'field' => '__id__'], absolute: false) }}"
        data-types="{{ json_encode($types, JSON_THROW_ON_ERROR) }}"
        data-currencies="{{ json_encode($currencies, JSON_THROW_ON_ERROR) }}"
    >
        <nav aria-label="{{ __('Breadcrumb') }}">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('app.workspace', ['workspace' => $workspace->ulid], absolute: false) }}">{{ $workspace->name }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('app.inventory', ['workspace' => $workspace->ulid, 'inventory' => $inventory->ulid], absolute: false) }}">{{ $inventory->name }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ __('Fields') }}</li>
            </ol>
        </nav>

        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <h1 class="page-title me-auto">{{ __('Fields') }}</h1>
            <div class="btn-list">
                <a class="btn" href="{{ route('app.inventory', ['workspace' => $workspace->ulid, 'inventory' => $inventory->ulid], absolute: false) }}">
                    <x-tabler-table aria-hidden="true" />
                    {{ __('Back to the items') }}
                </a>
                <button type="button" class="btn btn-primary" @click="startCreate">
                    <x-tabler-plus aria-hidden="true" />
                    {{ __('Add a field') }}
                </button>
            </div>
        </div>

        <div class="card">
            {{-- Hidden with the "hidden" attribute, not x-show: Alpine shows an element a tick late and hides it at once, so a
                 state that goes up and down faster than that (a local server answers in a few milliseconds) would leave it on. --}}
            <div class="card-body text-secondary" :hidden="!loading" role="status">
                <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                {{ __('Loading…') }}
            </div>

            {{-- The fields could not be read: the inventory is not known to have none, so the page says so and offers to try again. --}}
            <div class="card-body" :hidden="!loadFailed">
                <div class="alert alert-danger m-0" role="alert">
                    <div class="d-flex align-items-center gap-3">
                        <span>{{ __('The fields could not be loaded.') }}</span>
                        <button type="button" class="btn btn-sm ms-auto" @click="load" :disabled="loading">{{ __('Try again') }}</button>
                    </div>
                </div>
            </div>

            <div class="card-body" :hidden="!empty">
                <div class="empty">
                    <div class="empty-icon hero-icon text-primary">
                        <x-tabler-layout-columns aria-hidden="true" />
                    </div>
                    <p class="empty-title">{{ __('This inventory has no field yet') }}</p>
                    <p class="empty-subtitle text-secondary">
                        {{ __('Fields describe what each item holds: a name, a quantity, a price, a photo... Add them first, then the items.') }}
                    </p>
                    <div class="empty-action">
                        <button type="button" class="btn btn-primary" @click="startCreate">
                            <x-tabler-plus aria-hidden="true" />
                            {{ __('Add a field') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="list-group list-group-flush">
                <template x-for="field in fields" :key="field.id">
                    <div class="list-group-item">
                        <div class="row align-items-center g-2">
                            <div class="col-12 col-md">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="fw-bold text-break" x-text="field.name"></span>
                                    <span class="badge bg-secondary-lt" x-text="typeLabel(field)"></span>
                                    <span class="badge bg-red-lt" x-show="field.required">{{ __('Required') }}</span>
                                </div>
                                <div class="text-secondary small text-break" x-text="summary(field)"></div>
                            </div>
                            <div class="col-12 col-md-auto">
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-sm btn-icon" :aria-label="upLabel(field)" :title="upLabel(field)" @click="moveUp(field)" :disabled="busy || isFirst(field)">
                                        <x-tabler-arrow-up aria-hidden="true" />
                                    </button>
                                    <button type="button" class="btn btn-sm btn-icon" :aria-label="downLabel(field)" :title="downLabel(field)" @click="moveDown(field)" :disabled="busy || isLast(field)">
                                        <x-tabler-arrow-down aria-hidden="true" />
                                    </button>
                                    <button type="button" class="btn btn-sm btn-icon" :aria-label="editLabel(field)" :title="editLabel(field)" @click="startEdit(field)" :disabled="busy">
                                        <x-tabler-pencil aria-hidden="true" />
                                    </button>
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-danger" :aria-label="deleteLabel(field)" :title="deleteLabel(field)" @click="askDelete(field)" :disabled="busy">
                                        <x-tabler-trash aria-hidden="true" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="modal modal-blur fade" tabindex="-1" x-ref="fieldModal" role="dialog" aria-labelledby="field-modal-title" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
                <form class="modal-content" @submit.prevent="save" novalidate>
                    <div class="modal-header">
                        <h2 class="modal-title" id="field-modal-title" x-text="modalTitle"></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger" role="alert" x-show="formError" x-text="formError"></div>

                        <div class="mb-3">
                            <label class="form-label required" for="field-name">{{ __('Name') }}</label>
                            <input
                                id="field-name"
                                type="text"
                                :class="controlClass('name')"
                                x-model="form.name"
                                maxlength="120"
                                autocomplete="off"
                                data-autofocus
                                required
                            >
                            <div class="text-danger small mt-1" x-show="errorOf('name')" x-text="errorOf('name')"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="field-type">{{ __('Type') }}</label>
                            <select id="field-type" :class="selectClass('type')" :disabled="editing" @change="selectType($event)">
                                <template x-for="type in types" :key="type.type">
                                    <option :value="type.type" :selected="isPicked(form.type, type.type)" x-text="type.label"></option>
                                </template>
                            </select>
                            <div class="form-hint" x-show="editing">{{ __('The type of a field cannot be changed once it is created.') }}</div>
                            <div class="text-danger small mt-1" x-show="errorOf('type')" x-text="errorOf('type')"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" x-model="form.required">
                                <span class="form-check-label">{{ __('Required') }}</span>
                            </label>
                            <div class="form-hint">{{ __('An item cannot be saved without a value for a required field.') }}</div>
                        </div>

                        <template x-if="hasOption('max_length')">
                            <div class="mb-3">
                                <label class="form-label" for="field-max-length">{{ __('Maximum length') }}</label>
                                <input id="field-max-length" type="text" inputmode="numeric" :class="controlClass('options.max_length')" x-model="form.options.max_length" placeholder="255" autocomplete="off">
                                <div class="form-hint">{{ __('In characters, up to 1000. Leave empty for 255.') }}</div>
                                <div class="text-danger small mt-1" x-show="errorOf('options.max_length')" x-text="errorOf('options.max_length')"></div>
                            </div>
                        </template>

                        <template x-if="hasOption('decimals')">
                            <div class="mb-3">
                                <label class="form-label" for="field-decimals">{{ __('Decimals') }}</label>
                                <input id="field-decimals" type="text" inputmode="numeric" :class="controlClass('options.decimals')" x-model="form.options.decimals" autocomplete="off">
                                <div class="form-hint">{{ __('How many digits a value has after the decimal point.') }}</div>
                                <div class="text-danger small mt-1" x-show="errorOf('options.decimals')" x-text="errorOf('options.decimals')"></div>
                            </div>
                        </template>

                        <template x-if="isType('number')">
                            <div class="row">
                                <div class="col-12 col-sm-6 mb-3">
                                    <label class="form-label" for="field-min">{{ __('Minimum') }}</label>
                                    <input id="field-min" type="text" inputmode="decimal" :class="controlClass('options.min')" x-model="form.options.min" autocomplete="off">
                                    <div class="text-danger small mt-1" x-show="errorOf('options.min')" x-text="errorOf('options.min')"></div>
                                </div>
                                <div class="col-12 col-sm-6 mb-3">
                                    <label class="form-label" for="field-max">{{ __('Maximum') }}</label>
                                    <input id="field-max" type="text" inputmode="decimal" :class="controlClass('options.max')" x-model="form.options.max" autocomplete="off">
                                    <div class="text-danger small mt-1" x-show="errorOf('options.max')" x-text="errorOf('options.max')"></div>
                                </div>
                            </div>
                        </template>

                        <template x-if="hasOption('unit')">
                            <div class="mb-3">
                                <label class="form-label" for="field-unit">{{ __('Unit') }}</label>
                                <input id="field-unit" type="text" :class="controlClass('options.unit')" x-model="form.options.unit" maxlength="16" placeholder="kg" autocomplete="off">
                                <div class="form-hint">{{ __('Shown after the value: kg, m, pieces...') }}</div>
                                <div class="text-danger small mt-1" x-show="errorOf('options.unit')" x-text="errorOf('options.unit')"></div>
                            </div>
                        </template>

                        <template x-if="hasOption('currency')">
                            <div class="mb-3">
                                <label class="form-label" for="field-currency">{{ __('Currency') }}</label>
                                <select id="field-currency" :class="selectClass('options.currency')" @change="selectCurrency($event)">
                                    <template x-for="currency in currencies" :key="currency.code">
                                        <option :value="currency.code" :selected="isPicked(form.options.currency, currency.code)" x-text="currencyName(currency.code)"></option>
                                    </template>
                                </select>
                                <div class="text-danger small mt-1" x-show="errorOf('options.currency')" x-text="errorOf('options.currency')"></div>
                            </div>
                        </template>

                        <template x-if="isType('rating')">
                            <div class="mb-3">
                                <label class="form-label" for="field-rating-max">{{ __('Highest grade') }}</label>
                                <select id="field-rating-max" :class="selectClass('options.max')" @change="selectMax($event)">
                                    <template x-for="grade in ratingMaxima" :key="grade">
                                        <option :value="grade" :selected="isPicked(form.options.max, grade)" x-text="grade"></option>
                                    </template>
                                </select>
                                <div class="text-danger small mt-1" x-show="errorOf('options.max')" x-text="errorOf('options.max')"></div>
                            </div>
                        </template>

                        <template x-if="hasOption('format')">
                            <div class="mb-3">
                                <label class="form-label" for="field-format">{{ __('Format') }}</label>
                                <select id="field-format" :class="selectClass('options.format')" @change="selectFormat($event)">
                                    <template x-for="format in formats" :key="format.value">
                                        <option :value="format.value" :selected="isPicked(form.options.format, format.value)" x-text="format.label"></option>
                                    </template>
                                </select>
                                <div class="form-hint">{{ __('A format other than "Any code" checks the digits and the check digit of every code.') }}</div>
                                <div class="text-danger small mt-1" x-show="errorOf('options.format')" x-text="errorOf('options.format')"></div>
                            </div>
                        </template>

                        <template x-if="hasOption('choices')">
                            <div class="mb-3">
                                <div class="form-label required">{{ __('Choices') }}</div>
                                <template x-for="(choice, index) in form.options.choices" :key="index">
                                    <div class="mb-2">
                                        <div class="input-group">
                                            <span class="input-group-text"><span :class="swatch(choice)" aria-hidden="true"></span></span>
                                            <input type="text" class="form-control" x-model="choice.label" maxlength="100" autocomplete="off" aria-label="{{ __('Choice') }}">
                                            <select class="form-select flex-grow-0 w-auto" aria-label="{{ __('Color of the choice') }}" @change="selectColor(choice, $event)">
                                                <template x-for="color in colors" :key="color.value">
                                                    <option :value="color.value" :selected="isPicked(choice.color, color.value)" x-text="color.label"></option>
                                                </template>
                                            </select>
                                            <button type="button" class="btn btn-icon btn-outline-danger" :aria-label="removeChoiceLabel(choice)" :title="removeChoiceLabel(choice)" @click="removeChoice(index)">
                                                <x-tabler-trash aria-hidden="true" />
                                            </button>
                                        </div>
                                        <div class="text-danger small mt-1" x-show="choiceError(index)" x-text="choiceError(index)"></div>
                                    </div>
                                </template>
                                <button type="button" class="btn btn-sm" @click="addChoice">
                                    <x-tabler-plus aria-hidden="true" />
                                    {{ __('Add a choice') }}
                                </button>
                                <div class="form-hint mt-2">{{ __('Renaming or recoloring a choice keeps it on the items that have it. Removing it takes it off them.') }}</div>
                                <div class="text-danger small mt-1" x-show="errorOf('options.choices')" x-text="errorOf('options.choices')"></div>
                            </div>
                        </template>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn me-auto" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary" :disabled="!canSubmit">{{ __('Save') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal modal-blur fade" tabindex="-1" x-ref="deleteModal" role="dialog" aria-labelledby="field-delete-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="field-delete-title">{{ __('Delete the field') }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <p class="m-0 text-break" x-text="confirmText"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn me-auto" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="button" class="btn btn-danger" @click="confirmDelete" :disabled="busy">{{ __('Delete the field') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
