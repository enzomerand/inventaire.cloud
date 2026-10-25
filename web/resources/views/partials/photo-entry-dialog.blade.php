{{--
    The dialog that adds an item from photos, in four steps: the choice of the photos (one to five, from the camera or the gallery),
    the wait while they are analysed, the review of the values the analysis proposes (one row for each, all unchecked: nothing is
    accepted for the person) and, when something went wrong, what happened with a way back or a retry. "Continue" closes the dialog
    and opens the form of a new item with the checked values and the photos.

    Included in the root of the page of an inventory (app/inventory.blade.php) only when the instance, the workspace and the role
    allow it, so it reads the inventoryItems component (resources/js/components/inventory-items.js, with
    resources/js/photo-entry/dialog.js). The buttons that open it are in the header of the items card and in the empty state.

    There is no <form>: the Enter key must not start an analysis. The steps are four blocks, each hidden with :hidden when it is not
    the current one, and each opens with a heading that takes the cursor when the step changes (the first step puts it in the file
    chooser). The live region is on the page from the start: a region that is added together with its message is not reliably
    announced. The buttons of the steps are in one footer, shown with x-show.

    A row of the review is a "cell" (resources/js/fields/cell.js) with the name of the field, the evidence and the confidence added,
    so that the value is shown by partials/field-value, as the table shows it. What Claude wrote (the value, the evidence) is
    bound with x-text only, never as markup.

    The thumbnails are the photos as chosen (an address the browser made for each file) in the tiles of the photo fields.

    Do not put a display utility (d-flex...) on an element that carries :hidden or x-show: the utility wins and it never hides.
    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
<div class="modal modal-blur fade" tabindex="-1" x-ref="photoModal" role="dialog" aria-labelledby="photo-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="photo-modal-title">{{ __('Add from photos') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="visually-hidden" role="status" aria-live="polite" aria-atomic="true" x-text="photoStatus"></div>

                {{-- Step 1: the photos. No "capture" on the chooser: a phone then offers the camera and the gallery. --}}
                <div data-photo-step="choose" :hidden="!photoOnChoose">
                    <h3 class="h4" tabindex="-1" data-photo-heading>{{ __('Choose the photos') }}</h3>
                    <p class="text-secondary" x-text="photoChooseHint"></p>
                    <div class="mb-3">
                        <label class="form-label" for="photo-entry-pick">{{ __('Photos of the item') }}</label>
                        <input type="file" class="form-control" id="photo-entry-pick" accept="image/*" multiple aria-describedby="photo-entry-count photo-entry-message" :disabled="!canChoosePhotos" @change="choosePhotos">
                        <div class="form-hint" id="photo-entry-count" x-text="photoCountText"></div>
                        <div class="text-danger small mt-1" id="photo-entry-message" aria-live="polite" x-text="photoMessage"></div>
                    </div>
                    <ul class="list-unstyled d-flex flex-wrap gap-2 m-0 mb-3">
                        <template x-for="photo in photoPhotos" :key="photo.key">
                            <li class="app-photo-tile">
                                <img class="app-thumb-lg" :src="photo.url" :alt="photo.name" width="72" height="72" decoding="async">
                                <button type="button" class="btn-close app-tile-remove" :aria-label="photo.removeLabel" :title="photo.removeLabel" @click="removePhoto(photo)"></button>
                            </li>
                        </template>
                    </ul>
                    <div class="alert alert-info mb-0" role="note">
                        <div class="d-flex align-items-start gap-2">
                            <x-tabler-info-circle aria-hidden="true" />
                            <span>{{ __('The photos are sent to Anthropic (Claude) to be read, then deleted. Check every value before the item is created.') }}</span>
                        </div>
                    </div>
                </div>

                {{-- Step 2: the photos are redrawn, sent, and the analysis is waited for. --}}
                <div data-photo-step="working" :hidden="!photoOnWorking">
                    <h3 class="h4" tabindex="-1" data-photo-heading>{{ __('Analysis in progress') }}</h3>
                    <div class="d-flex align-items-center gap-2 text-secondary">
                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        <span x-text="photoWorkingText"></span>
                    </div>
                </div>

                {{-- Step 3: what the analysis proposes. A checkbox starts unchecked: the person accepts the values one by one. --}}
                <div data-photo-step="review" :hidden="!photoOnReview">
                    <h3 class="h4" tabindex="-1" data-photo-heading>{{ __('Check what was found') }}</h3>

                    <div :hidden="!photoHasRows">
                        <p class="text-secondary">{{ __('Nothing is filled in for you. Check the values you want in the new item: you can still change them in the form.') }}</p>
                        <p class="text-secondary small" x-text="photoSummary"></p>
                        <ul class="list-group">
                            <template x-for="cell in photoRows" :key="cell.key">
                                <li class="list-group-item" data-photo-row>
                                    <div class="d-flex align-items-start gap-3">
                                        <input type="checkbox" class="form-check-input flex-shrink-0 mt-1" :id="cell.checkId" :checked="isProposalChecked(cell)" :aria-labelledby="cell.labelledBy" :aria-describedby="cell.describedBy" @change="toggleProposal(cell, $event)">
                                        <label class="app-grow" :for="cell.checkId">
                                            <span class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                <span class="fw-medium text-break" :id="cell.nameId" x-text="cell.name"></span>
                                                <span :id="cell.levelId" :class="cell.levelClass" x-text="cell.levelText"></span>
                                            </span>
                                            <span class="d-block" :id="cell.valueId">
                                                @include('partials.field-value')
                                            </span>
                                            <span class="d-block text-secondary small text-break" :id="cell.evidenceId" x-text="cell.evidence"></span>
                                        </label>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div :hidden="photoHasRows">
                        <div class="empty">
                            <div class="empty-icon hero-icon text-primary">
                                <x-tabler-photo-search aria-hidden="true" />
                            </div>
                            <p class="empty-title">{{ __('No value found on these photos') }}</p>
                            <p class="empty-subtitle text-secondary">{{ __('Try clearer photos that show the label or the plate, or fill the item in yourself.') }}</p>
                        </div>
                    </div>
                </div>

                {{-- Something went wrong: what, and what the footer offers about it. --}}
                <div data-photo-step="error" :hidden="!photoOnError">
                    <h3 class="h4" tabindex="-1" data-photo-heading>{{ __('The analysis did not work') }}</h3>
                    <div class="alert alert-danger mb-0" role="alert">
                        <div class="d-flex align-items-start gap-2">
                            <x-tabler-alert-triangle aria-hidden="true" />
                            <span class="text-break" x-text="photoFailure.text"></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn me-auto" x-show="photoOnChoose" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary" x-show="photoOnChoose" :disabled="!canAnalysePhotos" @click="analysePhotos">{{ __('Analyse') }}</button>

                <button type="button" class="btn me-auto" x-show="photoOnWorking" @click="backToPhotos">{{ __('Cancel') }}</button>

                <button type="button" class="btn me-auto" x-show="photoOnReview" @click="backToPhotos">{{ __('Other photos') }}</button>
                <button type="button" class="btn btn-primary" x-show="photoOnReview" @click="continuePhotoEntry">{{ __('Continue') }}</button>

                <button type="button" class="btn me-auto" x-show="photoOnError" @click="backToPhotos">{{ __('Back to the photos') }}</button>
                <button type="button" class="btn btn-primary" x-show="photoCanRetry" @click="retryPhotoAnalysis">{{ __('Try again') }}</button>
            </div>
        </div>
    </div>
</div>
