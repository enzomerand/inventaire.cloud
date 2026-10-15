{{--
    The items of an inventory as a table: one column for each field, the dates the item was added and last changed, and
    the totals of the columns that add up (numbers and amounts) in the foot.

    Included in the items card of the page of an inventory (app/inventory.blade.php), so it reads the inventoryItems
    component (resources/js/components/inventory-items.js). The header of a column that can be sorted is a button
    (aria-sort says the state; a click goes ascending, descending, then back to the newest first).

    position-relative: the visually hidden label of the last header is absolutely positioned, and without a positioned
    ancestor it would sit outside the scrolling area, making the whole page scroll sideways on a phone.
    Do not put a display utility (d-flex...) on an element that carries x-show or :hidden: the utility wins and it never hides.
--}}
<div class="table-responsive position-relative" :hidden="!hasTable" :class="tableClass" :aria-busy="busyAttribute">
    <table class="table table-vcenter card-table table-hover">
        <thead>
            <tr>
                <template x-for="field in fields" :key="field.id">
                    <th class="text-nowrap" :aria-sort="fieldAriaSort(field)">
                        <template x-if="isSortable(field)">
                            <button type="button" class="table-sort" :class="sortClass(field.id)" @click="toggleSort(field.id)" x-text="field.name"></button>
                        </template>
                        <template x-if="!isSortable(field)">
                            <span x-text="field.name"></span>
                        </template>
                    </th>
                </template>
                <th class="text-nowrap" :aria-sort="ariaSort('created_at')">
                    <button type="button" class="table-sort" :class="sortClass('created_at')" @click="toggleSort('created_at')">{{ __('Date added') }}</button>
                </th>
                <th class="text-nowrap" :aria-sort="ariaSort('updated_at')">
                    <button type="button" class="table-sort" :class="sortClass('updated_at')" @click="toggleSort('updated_at')">{{ __('Last modified') }}</button>
                </th>
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
                    <td class="text-secondary text-nowrap small" x-text="row.created"></td>
                    <td class="text-secondary text-nowrap small" x-text="row.updated"></td>
                    <td class="w-1">
                        <button type="button" class="btn btn-sm btn-icon" :aria-label="openLabel" :title="openLabel" @click.stop="open(row)">
                            <x-tabler-pencil aria-hidden="true" />
                        </button>
                    </td>
                </tr>
            </template>
        </tbody>
        <tfoot class="table-group-divider" :hidden="!showTotals">
            <tr>
                <template x-for="cell in totalRow" :key="cell.key">
                    <td class="fw-bold text-nowrap">
                        <template x-if="cell.first">
                            <span class="fw-normal text-secondary me-2" x-text="totalsLabel"></span>
                        </template>
                        <span x-text="cell.text"></span>
                    </td>
                </template>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
</div>
