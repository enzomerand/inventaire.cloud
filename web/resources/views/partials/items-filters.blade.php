{{--
    The panel of the filters of the items of an inventory: one row for each field the server can query (a field of a type it
    does not know is left out), with the conditions its kind takes (a
    lowest and a highest value for a number or a date, an answer for a yes / no, the choices for a choice) and, for every
    kind, whether it holds a value or none.

    A panel that slides in from the edge (Tabler's offcanvas, opened by openFilters in resources/js/grid/criteria.js), so
    that it fits a phone. What the boxes hold is a draft until "Apply": the filters in force change only then.

    Do not put a display utility (d-flex...) on an element that carries x-show or :hidden: the utility wins and it never hides.
--}}
<div class="offcanvas offcanvas-end" tabindex="-1" x-ref="filters" role="dialog" aria-labelledby="item-filters-title" aria-hidden="true">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title" id="item-filters-title">{{ __('Filters') }}</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="{{ __('Close') }}"></button>
    </div>
    <form class="d-flex flex-column flex-grow-1 overflow-hidden" @submit.prevent="applyFilters" novalidate>
        <div class="offcanvas-body">
            <template x-for="field in filterableFields" :key="field.id">
                <fieldset class="mb-4">
                    <legend class="form-label fs-4 text-break" x-text="field.name"></legend>

                    <template x-if="hasRange(field)">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small text-secondary" :for="filterId(field, 'min')">{{ __('From') }}</label>
                                <input
                                    :id="filterId(field, 'min')"
                                    :type="rangeInputType(field)"
                                    :inputmode="rangeInputMode(field)"
                                    :class="filterInputClass(field, 'min')"
                                    :aria-invalid="filterInvalid(field, 'min')"
                                    :aria-describedby="filterDescribedBy(field, 'min')"
                                    :value="draftValue(field, 'min')"
                                    :disabled="draftEmpty(field)"
                                    @input="setDraft(field, 'min', $event)"
                                    autocomplete="off"
                                >
                                <div class="invalid-feedback" :id="filterErrorId(field, 'min')" x-text="filterError(field, 'min')"></div>
                            </div>
                            <div class="col-6">
                                <label class="form-label small text-secondary" :for="filterId(field, 'max')">{{ __('To') }}</label>
                                <input
                                    :id="filterId(field, 'max')"
                                    :type="rangeInputType(field)"
                                    :inputmode="rangeInputMode(field)"
                                    :class="filterInputClass(field, 'max')"
                                    :aria-invalid="filterInvalid(field, 'max')"
                                    :aria-describedby="filterDescribedBy(field, 'max')"
                                    :value="draftValue(field, 'max')"
                                    :disabled="draftEmpty(field)"
                                    @input="setDraft(field, 'max', $event)"
                                    autocomplete="off"
                                >
                                <div class="invalid-feedback" :id="filterErrorId(field, 'max')" x-text="filterError(field, 'max')"></div>
                            </div>
                        </div>
                    </template>

                    <template x-if="hasAnswer(field)">
                        <div class="mb-2">
                            <label class="visually-hidden" :for="filterId(field, 'is')">{{ __('Answer') }}</label>
                            <select class="form-select" :id="filterId(field, 'is')" :disabled="draftEmpty(field)" @change="setDraft(field, 'is', $event)">
                                <option value="" :selected="draftIs(field, 'is', '')">{{ __('Any') }}</option>
                                <option value="true" :selected="draftIs(field, 'is', 'true')">{{ __('Yes') }}</option>
                                <option value="false" :selected="draftIs(field, 'is', 'false')">{{ __('No') }}</option>
                            </select>
                        </div>
                    </template>

                    <template x-if="hasChoiceList(field)">
                        <div class="mb-2" role="group" :aria-label="field.name">
                            <template x-for="choice in choices(field)" :key="choice.id">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" :checked="draftHasChoice(field, choice.id)" :disabled="draftEmpty(field)" @change="toggleDraftChoice(field, choice.id, $event)">
                                    <span class="form-check-label" x-text="choice.label"></span>
                                </label>
                            </template>
                        </div>
                    </template>

                    <label class="visually-hidden" :for="filterId(field, 'presence')">{{ __('Value') }}</label>
                    <select class="form-select" :id="filterId(field, 'presence')" @change="setDraft(field, 'presence', $event)">
                        <option value="" :selected="draftIs(field, 'presence', '')">{{ __('Any value') }}</option>
                        <option value="empty" :selected="draftIs(field, 'presence', 'empty')">{{ __('Empty') }}</option>
                        <option value="filled" :selected="draftIs(field, 'presence', 'filled')">{{ __('Not empty') }}</option>
                    </select>
                </fieldset>
            </template>
        </div>
        <div class="border-top p-3 d-flex gap-2">
            <button type="button" class="btn me-auto" @click="resetFilters">{{ __('Reset') }}</button>
            <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
        </div>
    </form>
</div>
