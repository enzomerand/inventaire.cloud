{{--
    The dialog that imports the items of a spreadsheet into an inventory, in three steps: the file (a CSV, TSV, TXT, XLSX or ODS
    file, sent as soon as it is chosen), the mapping (where each column of the file goes: nowhere, an existing field, or a field
    to create; plus how the file and its cells are read), and the result (the import is made by a job: the dialog follows it,
    then tells what it did and lists the rows it could not read).

    Included in the root of the page of an inventory (app/inventory.blade.php), so it reads the inventoryItems component
    (resources/js/components/inventory-items.js, with resources/js/import/dialog.js). The buttons that open it are in
    partials/items-toolbar and in the empty states of the page.

    There is no <form>: the Enter key in the name of a new field must not start an import. The steps are three blocks, each
    hidden with :hidden when it is not the current one, and each opens with a title that takes the cursor when the step
    changes. The live region is on the page from the start: a region that is added together with its message is not
    reliably announced. What stops the import from starting is in the footer, which stays in view while the body scrolls.

    The columns are a list, not a table: on a phone each one is a card (the name of the column, its examples, its
    destination), from the "md" breakpoint they are three columns under a header row. The controls carry an aria-label that
    names their column, as the visible captions are only there for the small screens.

    The report of the rows in error is an ARIA table made of divs rather than a <table>: on a phone each row is a card (the row
    and the column on one line, the problem under them), from the "md" breakpoint they are three columns under a header row.
    The header row is not displayed on a phone (and so is out of the accessibility tree too), so each cell carries its own
    caption there, which is not read nor shown from the "md" breakpoint, where the header names the columns.

    Do not put a display utility (d-flex...) on an element that carries :hidden or x-show: the utility wins and it never hides.
    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
<div class="modal modal-blur fade" tabindex="-1" x-ref="importModal" role="dialog" aria-labelledby="import-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="import-modal-title">{{ __('Import items') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="visually-hidden" role="status" aria-live="polite" aria-atomic="true" x-text="importStatus"></div>

                <div class="text-secondary" :hidden="!importOnLoading">
                    <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                    {{ __('Loading…') }}
                </div>

                {{-- Step 1: the file. --}}
                <div data-import-step="file" :hidden="!importOnFile">
                    <h3 class="h4" tabindex="-1" data-import-heading>{{ __('Choose a file') }}</h3>
                    <p class="text-secondary">{{ __('The rows of a spreadsheet become items of this inventory. In the next step, you match the columns of the file with the fields, and you can create the fields that do not exist yet.') }}</p>
                    <div class="mb-3">
                        <label class="form-label" for="import-file">{{ __('Spreadsheet file') }}</label>
                        <input type="file" class="form-control" id="import-file" aria-describedby="import-file-hint" :accept="importAccept" :disabled="importWorking" @change="onImportFile">
                        <div class="form-hint" id="import-file-hint" x-text="importFileHint"></div>
                    </div>
                    <div class="text-secondary" :hidden="!importUploading">
                        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                        <span x-text="importUploadText"></span>
                    </div>
                    <p class="text-secondary small mb-0">{{ __('Only the active sheet of a spreadsheet is read. The file is deleted from the server as soon as the import ends.') }}</p>
                </div>

                {{-- Step 2: the mapping. --}}
                <div data-import-step="mapping" :hidden="!importOnMapping">
                    <h3 class="h4" tabindex="-1" data-import-heading>{{ __('Match the columns') }}</h3>
                    <p class="text-secondary mb-1 text-break" x-text="importFileInfo"></p>
                    <p class="text-secondary">{{ __('For each column of the file, choose the field its values go to. A column can also be ignored, or create a new field.') }}</p>

                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="form-check mb-0">
                                <input type="checkbox" class="form-check-input" id="import-header" x-model="importHasHeader" :disabled="importWorking" @change="reanalyzeImport">
                                <span class="form-check-label">{{ __('The first row holds the names of the columns') }}</span>
                            </label>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4" :hidden="!importIsCsv">
                            <label class="form-label" for="import-delimiter">{{ __('Separator') }}</label>
                            <select id="import-delimiter" class="form-select" x-model="importDelimiter" :disabled="importWorking" @change="reanalyzeImport">
                                <option value="semicolon">{{ __('Semicolon ( ; )') }}</option>
                                <option value="comma">{{ __('Comma ( , )') }}</option>
                                <option value="tab">{{ __('Tab') }}</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4" :hidden="!importIsCsv">
                            <label class="form-label" for="import-encoding">{{ __('Encoding') }}</label>
                            <select id="import-encoding" class="form-select" x-model="importEncoding" :disabled="importWorking" @change="reanalyzeImport">
                                <option value="utf-8">{{ __('UTF-8') }}</option>
                                <option value="windows-1252">{{ __('Windows-1252 (Western European)') }}</option>
                                <option value="utf-16">{{ __('UTF-16') }}</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label class="form-label" for="import-decimal">{{ __('Decimal sign') }}</label>
                            <select id="import-decimal" class="form-select" x-model="importDecimal" :disabled="importWorking">
                                <option value="comma">{{ __('Comma (12,5)') }}</option>
                                <option value="point">{{ __('Point (12.5)') }}</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label class="form-label" for="import-date-order">{{ __('Order of the dates') }}</label>
                            <select id="import-date-order" class="form-select" x-model="importDateOrder" :disabled="importWorking">
                                <option value="dmy">{{ __('Day, month, year (31/12/2026)') }}</option>
                                <option value="mdy">{{ __('Month, day, year (12/31/2026)') }}</option>
                                <option value="ymd">{{ __('Year, month, day (2026-12-31)') }}</option>
                            </select>
                        </div>
                        <div class="col-12 col-lg-8">
                            <fieldset>
                                <legend class="form-label">{{ __('If a row is not valid') }}</legend>
                                <div class="form-check">
                                    <input type="radio" name="import-on-error" class="form-check-input" id="import-on-error-abort" value="abort" x-model="importOnError" :disabled="importWorking">
                                    <label class="form-check-label" for="import-on-error-abort">{{ __('Cancel the whole import') }}</label>
                                </div>
                                <div class="form-check mb-0">
                                    <input type="radio" name="import-on-error" class="form-check-input" id="import-on-error-skip" value="skip" x-model="importOnError" :disabled="importWorking">
                                    <label class="form-check-label" for="import-on-error-skip">{{ __('Skip the rows in error and import the others') }}</label>
                                </div>
                            </fieldset>
                        </div>
                    </div>

                    <hr>

                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h4 class="h5 m-0" id="import-columns-title">{{ __('Columns of the file') }}</h4>
                        <span class="spinner-border spinner-border-sm text-secondary" :hidden="!importAnalysing" aria-hidden="true"></span>
                    </div>
                    <p class="text-secondary" x-text="importMappingSummary"></p>

                    <ul class="list-group" aria-labelledby="import-columns-title">
                        <li class="list-group-item d-none d-md-block text-secondary small" aria-hidden="true">
                            <div class="row g-3">
                                <div class="col-md-3">{{ __('Column') }}</div>
                                <div class="col-md-4">{{ __('Examples') }}</div>
                                <div class="col-md-5">{{ __('Destination') }}</div>
                            </div>
                        </li>
                        <template x-for="row in importMappingRows" :key="row.index">
                            <li class="list-group-item" data-import-column>
                                <div class="row g-2 g-md-3 align-items-start">
                                    <div class="col-12 col-md-3">
                                        <div class="text-secondary small d-md-none" aria-hidden="true">{{ __('Column') }}</div>
                                        <div class="fw-medium text-break" x-text="row.header"></div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <div class="text-secondary small d-md-none" aria-hidden="true">{{ __('Examples') }}</div>
                                        <div class="text-secondary text-break">
                                            <span :hidden="row.samples === ''" x-text="row.samples"></span>
                                            <span class="fst-italic" :hidden="row.samples !== ''">{{ __('Empty column') }}</span>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-5">
                                        <div class="text-secondary small d-md-none" aria-hidden="true">{{ __('Destination') }}</div>
                                        <select class="form-select" :id="row.destinationId" :aria-label="row.destinationLabel" :aria-invalid="row.destinationInvalid" :aria-describedby="row.describedBy" :disabled="importWorking" @change="setImportDestination(row, $event)">
                                            <option value="ignore" :selected="row.value === 'ignore'">{{ __('Ignore this column') }}</option>
                                            <template x-if="canManageFields">
                                                <option value="new" :selected="row.value === 'new'">{{ __('New field') }}</option>
                                            </template>
                                            <template x-if="hasImportFields">
                                                <optgroup label="{{ __('Existing field') }}">
                                                    <template x-for="option in importFieldOptions" :key="option.id">
                                                        <option :value="option.value" :selected="row.value === option.value" x-text="option.name"></option>
                                                    </template>
                                                </optgroup>
                                            </template>
                                        </select>
                                        <template x-if="row.isNew">
                                            <div class="mt-2">
                                                <div class="row g-2">
                                                    <div class="col-12 col-sm-5">
                                                        <select class="form-select" :id="row.typeId" :aria-label="row.typeLabel" :disabled="importWorking" @change="setImportType(row, $event)">
                                                            <template x-for="option in importTypeOptions" :key="option.id">
                                                                <option :value="option.id" :selected="row.type === option.id" x-text="option.label"></option>
                                                            </template>
                                                        </select>
                                                    </div>
                                                    <div class="col-12 col-sm-7">
                                                        <input type="text" class="form-control" :id="row.nameId" :aria-label="row.nameLabel" :aria-invalid="row.nameInvalid" :aria-describedby="row.describedBy" maxlength="120" autocomplete="off" :value="row.name" :disabled="importWorking" @input="setImportName(row, $event)">
                                                    </div>
                                                </div>
                                                <div class="form-hint" :hidden="!row.isSelect">{{ __('The choices are made from the different values of the column.') }}</div>
                                            </div>
                                        </template>
                                        <div class="text-danger small mt-1" :id="row.errorId" :hidden="row.error === ''" x-text="row.error"></div>
                                        <div class="alert alert-warning small py-2 px-3 mt-2 mb-0" :id="row.warningId" :hidden="row.warning === ''" x-text="row.warning"></div>
                                    </div>
                                </div>
                            </li>
                        </template>
                    </ul>

                    <p class="text-secondary small mt-3 mb-0">{{ __('If you close this window, the file waits for you for a limited time.') }}</p>
                </div>

                {{-- Step 3: the result. --}}
                <div data-import-step="result" :hidden="!importOnResult">
                    <h3 class="h4" tabindex="-1" data-import-heading x-text="importResultTitle"></h3>
                    <p class="text-secondary text-break" x-text="importFileInfo"></p>

                    <div :hidden="!importWaiting">
                        <div class="text-secondary">
                            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                            {{ __('The import is running. You can close this window: it goes on without it.') }}
                        </div>
                    </div>

                    <div class="alert alert-warning" :hidden="!importFollowFailed">
                        <div class="d-flex align-items-center gap-3">
                            <span>{{ __('The progress of the import could not be read.') }}</span>
                            <button type="button" class="btn btn-sm ms-auto" @click="retryImport">{{ __('Try again') }}</button>
                        </div>
                    </div>

                    <div class="alert alert-success" :hidden="!importCompleted">
                        <ul class="mb-0 ps-3">
                            <template x-for="line in importResultLines" :key="line">
                                <li x-text="line"></li>
                            </template>
                        </ul>
                    </div>

                    <div class="alert alert-danger" :hidden="!importFailed">
                        <p class="mb-0 text-break" x-text="importFailureText"></p>
                        <ul class="mt-2 mb-0 ps-3">
                            <template x-for="line in importResultLines" :key="line">
                                <li x-text="line"></li>
                            </template>
                        </ul>
                    </div>

                    <div :hidden="!hasImportReport">
                        <h4 class="h5" id="import-report-title">{{ __('Rows in error') }}</h4>
                        <p class="text-secondary" :hidden="importReportNote === ''" x-text="importReportNote"></p>
                        <div role="table" aria-labelledby="import-report-title">
                            <div role="row" class="row g-2 d-none d-md-flex border-bottom pb-2 text-secondary small">
                                <div role="columnheader" class="col-md-2">{{ __('Row') }}</div>
                                <div role="columnheader" class="col-md-3">{{ __('Column') }}</div>
                                <div role="columnheader" class="col-md-7">{{ __('Problem') }}</div>
                            </div>
                            <template x-for="line in importReport" :key="line.key">
                                <div role="row" class="row g-2 border-bottom py-2" data-import-error>
                                    <div role="cell" class="col-4 col-md-2">
                                        <div class="text-secondary small d-md-none">{{ __('Row') }}</div>
                                        <div x-text="line.row"></div>
                                    </div>
                                    <div role="cell" class="col-8 col-md-3 text-break">
                                        <div class="text-secondary small d-md-none">{{ __('Column') }}</div>
                                        <div x-text="line.column"></div>
                                    </div>
                                    <div role="cell" class="col-12 col-md-7 text-break">
                                        <div class="text-secondary small d-md-none">{{ __('Problem') }}</div>
                                        <div x-text="line.message"></div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <div class="alert alert-danger w-100 mb-0 text-break" role="alert" :hidden="!hasImportAlerts">
                    <ul class="mb-0 ps-3">
                        <template x-for="alert in importAlerts" :key="alert.key">
                            <li x-text="alert.text"></li>
                        </template>
                    </ul>
                </div>
                <button type="button" class="btn me-auto" x-show="importOnMapping" :disabled="importWorking" @click="cancelImport">
                    <span class="spinner-border spinner-border-sm me-2" :hidden="!importCancelling" aria-hidden="true"></span>
                    {{ __('Cancel the import') }}
                </button>
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Close') }}</button>
                <button type="button" class="btn btn-primary" x-show="importOnMapping" :disabled="importWorking" :aria-busy="importStarting" @click="startImport">
                    <span class="spinner-border spinner-border-sm me-2" :hidden="!importStarting" aria-hidden="true"></span>
                    {{ __('Start the import') }}
                </button>
                <button type="button" class="btn btn-primary" x-show="importOnResult && !importWaiting" @click="restartImport">
                    <x-tabler-file-import aria-hidden="true" />
                    {{ __('Import another file') }}
                </button>
            </div>
        </div>
    </div>
</div>
