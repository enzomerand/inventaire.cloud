{{--
    The control a cell of the table turns into when an editor edits its value in place: a box, or a select. Enter or leaving
    the control saves, Escape gives up. A value the server refuses is shown under the control (which points to the message),
    and stays until it is fixed or given up.

    Included in the cell of the table (partials/items-table.blade.php), so it reads the inventoryItems component
    (resources/js/grid/inline.js). Only one cell is edited at a time, hence the fixed id.

    A click inside it is the person's own, never a click on the row (which opens the dialog of the item).
--}}
<div @click.stop>
    <div class="d-flex align-items-center gap-2">
        <template x-if="inlineControl === 'input'">
            <input
                id="item-inline-editor"
                :type="inlineType"
                :inputmode="inlineMode"
                :maxlength="inlineMax"
                :class="inlineClass"
                :value="inlineInput"
                :disabled="inlineSaving"
                :aria-label="inlineLabel"
                :aria-invalid="inlineInvalid"
                :aria-describedby="inlineDescribedBy"
                @input="onInlineInput"
                @keydown.enter.prevent="commitInline('enter')"
                @keydown.escape.prevent="cancelInline"
                @blur="commitInline('blur')"
                autocomplete="off"
            >
        </template>
        <template x-if="inlineControl === 'select'">
            <select
                id="item-inline-editor"
                :class="inlineSelectClass"
                :disabled="inlineSaving"
                :aria-label="inlineLabel"
                :aria-invalid="inlineInvalid"
                :aria-describedby="inlineDescribedBy"
                @change="onInlineInput"
                @keydown.enter.prevent="commitInline('enter')"
                @keydown.escape.prevent="cancelInline"
                @blur="commitInline('blur')"
            >
                <template x-for="option in inlineOptions" :key="option.value">
                    <option :value="option.value" :selected="option.value === inlineInput" x-text="option.label"></option>
                </template>
            </select>
        </template>
        <span class="spinner-border spinner-border-sm text-secondary" :hidden="!inlineSaving" aria-hidden="true"></span>
    </div>
    <div id="item-inline-error" class="text-danger small mt-1" role="alert" x-show="inlineError" x-text="inlineError"></div>
</div>
