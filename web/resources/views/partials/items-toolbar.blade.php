{{--
    What narrows, orders and lays out the items of an inventory: the search box, the filters button (with how many filters
    are in force), the choice between the table and the gallery, the sort, the filters as removable chips, and how many
    items match.

    Included in the items card of the page of an inventory (app/inventory.blade.php), so it reads the inventoryItems
    component (resources/js/components/inventory-items.js, with resources/js/grid/criteria.js). The sort select and its
    direction are the way to sort on a phone, where the headers of the table are out of reach; the table shows them below
    the "sm" breakpoint only, the gallery always.

    Do not put a display utility (d-flex...) on an element that carries :hidden: the utility wins and it never hides.

    A chip wraps (a long name must not push the page sideways on a phone), and its close button never shrinks. Removing a
    chip puts the cursor on the next one, else on the filters button (see removeChip in resources/js/grid/criteria.js).
--}}
<div class="card-body border-bottom" :hidden="!showToolbar">
    <div class="row g-2 align-items-center">
        <div class="col-12 col-sm">
            <div class="input-group">
                <span class="input-group-text"><x-tabler-search aria-hidden="true" /></span>
                <input
                    type="search"
                    class="form-control app-search"
                    x-ref="search"
                    maxlength="200"
                    autocomplete="off"
                    placeholder="{{ __('Search') }}"
                    aria-label="{{ __('Search') }}"
                    :value="searchText"
                    @input="onSearchInput"
                    @keydown.enter.prevent="submitSearch"
                >
                <button type="button" class="btn btn-icon" :hidden="!hasSearchText" aria-label="{{ __('Clear the search') }}" title="{{ __('Clear the search') }}" @click="clearSearch">
                    <x-tabler-x aria-hidden="true" />
                </button>
            </div>
        </div>
        <div class="col-auto">
            <button type="button" class="btn" aria-haspopup="dialog" x-ref="filtersButton" @click="openFilters">
                <x-tabler-adjustments-horizontal aria-hidden="true" />
                {{ __('Filters') }}
                <span class="badge bg-primary ms-2" :hidden="filterCount === 0" x-text="filterCount"></span>
            </button>
        </div>
        <div class="col-auto">
            <div class="btn-group" role="group" aria-label="{{ __('View') }}">
                <button type="button" class="btn btn-icon" :class="tableButtonClass" :aria-pressed="tablePressed" aria-label="{{ __('Table') }}" title="{{ __('Table') }}" @click="showAsTable">
                    <x-tabler-table aria-hidden="true" />
                </button>
                <button type="button" class="btn btn-icon" :class="galleryButtonClass" :aria-pressed="galleryPressed" aria-label="{{ __('Gallery') }}" title="{{ __('Gallery') }}" @click="showAsGallery">
                    <x-tabler-layout-grid aria-hidden="true" />
                </button>
            </div>
        </div>
        <div class="col-12 col-sm-auto" :class="sortBarClass">
            <div class="input-group">
                <label class="input-group-text" for="item-sort">{{ __('Sort by') }}</label>
                <select id="item-sort" class="form-select" @change="chooseSort($event)">
                    <template x-for="option in sortOptions" :key="option.key">
                        <option :value="option.value" :selected="option.value === query.sort" x-text="option.label"></option>
                    </template>
                </select>
                <button type="button" class="btn btn-icon" :disabled="!isSorted" :aria-label="directionLabel" :title="directionLabel" @click="flipDirection">
                    <span :hidden="isDescending"><x-tabler-sort-ascending aria-hidden="true" /></span>
                    <span :hidden="!isDescending"><x-tabler-sort-descending aria-hidden="true" /></span>
                </button>
            </div>
        </div>
    </div>

    <div class="mt-2" :hidden="chips.length === 0">
        <div class="d-flex flex-wrap gap-1">
            <template x-for="chip in chips" :key="chip.key">
                <span class="badge bg-blue-lt d-inline-flex align-items-center gap-1 text-wrap text-break text-start lh-sm mw-100">
                    <span x-text="chip.label"></span>
                    <button type="button" class="btn-close flex-shrink-0" :data-chip-remove="chip.fieldId" :aria-label="chip.removeLabel" @click="removeChip(chip)"></button>
                </span>
            </template>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 mt-2">
        <p class="m-0 text-secondary" role="status" x-text="countLabel"></p>
        <span class="spinner-border spinner-border-sm text-secondary" :hidden="!refreshing" aria-hidden="true"></span>
    </div>
</div>
