{{--
    The dialog that exports the items of an inventory: the format, which items (all of them or the current view of the grid),
    the options of the format (the separator of a CSV file, the files of a ZIP), and the list of the exports the person asked
    for lately, each with its status and, once it is ready, a link to its file.

    Included in the root of the page of an inventory (app/inventory.blade.php), so it reads the inventoryItems component
    (resources/js/components/inventory-items.js, with resources/js/export/dialog.js). The button that opens it is in
    partials/items-toolbar.

    The formats are listed by the component (its labels and what each is good for are messages the scripts need anyway, to
    name an export in the list), as radio cards. The live region is on the page from the start: a region that is added together
    with its message is not reliably announced. The error is in the footer, which stays in view while the body scrolls. The
    link to a file carries no rel="noreferrer": the API recognises the web front by its Referer.

    Do not put a display utility (d-flex...) on an element that carries :hidden: the utility wins and it never hides.
    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
<div class="modal modal-blur fade" tabindex="-1" x-ref="exportModal" role="dialog" aria-labelledby="export-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <form class="modal-content" @submit.prevent="submitExport" novalidate>
            <div class="modal-header">
                <h2 class="modal-title" id="export-modal-title">{{ __('Export the items') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="visually-hidden" role="status" aria-live="polite" aria-atomic="true" x-text="exportStatus"></div>

                <fieldset class="mb-3">
                    <legend class="form-label">{{ __('Format') }}</legend>
                    <div class="form-selectgroup form-selectgroup-boxes d-flex flex-column">
                        <template x-for="format in exportFormats" :key="format.id">
                            <label class="form-selectgroup-item flex-fill">
                                <input type="radio" name="export-format" class="form-selectgroup-input" :id="format.inputId" :value="format.id" x-model="exportFormat">
                                <span class="form-selectgroup-label d-flex align-items-center p-3">
                                    <span class="me-3"><span class="form-selectgroup-check"></span></span>
                                    <span class="d-block text-start">
                                        <strong class="d-block" x-text="format.label"></strong>
                                        <span class="d-block text-secondary" x-text="format.hint"></span>
                                    </span>
                                </span>
                            </label>
                        </template>
                    </div>
                </fieldset>

                <fieldset class="mb-3">
                    <legend class="form-label">{{ __('Items to export') }}</legend>
                    <div class="form-check">
                        <input type="radio" name="export-scope" class="form-check-input" id="export-scope-all" value="all" x-model="exportScope">
                        <label class="form-check-label" for="export-scope-all">{{ __('All items') }}</label>
                    </div>
                    <div class="form-check mb-0">
                        <input type="radio" name="export-scope" class="form-check-input" id="export-scope-view" value="view" aria-describedby="export-scope-view-hint" :disabled="!viewScopeAvailable" x-model="exportScope">
                        <label class="form-check-label" for="export-scope-view">{{ __('Current view') }}</label>
                        <span class="form-hint d-block" id="export-scope-view-hint" x-text="viewScopeHint"></span>
                    </div>
                </fieldset>

                <div class="mb-3" :hidden="!showDelimiter">
                    <label class="form-label" for="export-delimiter">{{ __('CSV separator') }}</label>
                    <select id="export-delimiter" class="form-select" aria-describedby="export-delimiter-hint" x-model="exportDelimiter">
                        <option value="semicolon">{{ __('Semicolon (;)') }}</option>
                        <option value="comma">{{ __('Comma (,)') }}</option>
                        <option value="tab">{{ __('Tab') }}</option>
                    </select>
                    <div class="form-hint" id="export-delimiter-hint">{{ __('Excel in French, like in many other languages, expects a semicolon.') }}</div>
                </div>

                <fieldset class="mb-3" :hidden="!showFiles">
                    <legend class="form-label">{{ __('Files to include') }}</legend>
                    <div class="form-check">
                        <input type="radio" name="export-files" class="form-check-input" id="export-files-photos" value="photos" x-model="exportFiles">
                        <label class="form-check-label" for="export-files-photos">{{ __('The photos of the photo fields') }}</label>
                    </div>
                    <div class="form-check mb-0">
                        <input type="radio" name="export-files" class="form-check-input" id="export-files-all" value="all" x-model="exportFiles">
                        <label class="form-check-label" for="export-files-all">{{ __('All the attachments, photos and other files') }}</label>
                    </div>
                </fieldset>

                <hr>

                <div class="d-flex align-items-center gap-2 mb-2">
                    <h3 class="h4 m-0" id="export-list-title">{{ __('Recent exports') }}</h3>
                    <span class="spinner-border spinner-border-sm text-secondary" :hidden="!exportsLoading" aria-hidden="true"></span>
                </div>

                <div class="alert alert-warning" :hidden="!exportsFailed">
                    <div class="d-flex align-items-center gap-3">
                        <span>{{ __('The exports could not be loaded.') }}</span>
                        <button type="button" class="btn btn-sm ms-auto" @click="loadExports" :disabled="exportsLoading">{{ __('Try again') }}</button>
                    </div>
                </div>

                <p class="text-secondary m-0" :hidden="!noExports">{{ __('No export yet. An export is kept for a limited time, then deleted.') }}</p>

                <ul class="list-group" aria-labelledby="export-list-title" :hidden="!hasExports">
                    <template x-for="row in exportRows" :key="row.id">
                        <li class="list-group-item">
                            <div class="d-flex flex-wrap align-items-start gap-2">
                                <div class="me-auto text-break">
                                    <div class="fw-medium" x-text="row.title"></div>
                                    <div class="text-secondary small" x-text="row.details"></div>
                                    <div class="text-danger small" :hidden="row.error === ''" x-text="row.error"></div>
                                </div>
                                <span class="badge" :class="row.badgeClass" x-text="row.statusLabel"></span>
                            </div>
                            <div class="btn-list mt-2">
                                <a class="btn btn-sm btn-primary" download :href="row.url" :hidden="!row.ready" :aria-label="row.downloadLabel">
                                    <x-tabler-download aria-hidden="true" />
                                    {{ __('Download') }}
                                </a>
                                <button type="button" class="btn btn-sm" data-export-delete :disabled="row.deleting" :aria-label="row.deleteLabel" @click="removeExport(row)">
                                    <x-tabler-trash aria-hidden="true" />
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>
            <div class="modal-footer">
                <div class="alert alert-danger w-100 mb-0 text-break" role="alert" :hidden="exportError === ''" x-text="exportError"></div>
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Close') }}</button>
                <button type="submit" class="btn btn-primary" x-ref="exportSubmit" :disabled="exportBusy" :aria-busy="exportBusy">
                    <span class="spinner-border spinner-border-sm me-2" :hidden="!exportBusy" aria-hidden="true"></span>
                    {{ __('Start the export') }}
                </button>
            </div>
        </form>
    </div>
</div>
