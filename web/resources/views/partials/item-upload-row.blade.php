{{--
    A file on its way up, waiting for its turn, or one that was refused: its name, a spinner while it is sent, and why it
    failed. A refused file stays until the person dismisses it, or tries to send it again (when it can be: a file the browser
    already refused, too large say, cannot).

    Included in a template that has an "upload" in scope: the photo and file fields of the dialog of an item
    (partials/item-file-field) and its attachments tab (partials/item-attachments).

    Nothing here is a live region: the progress of the uploads is announced by the one that is on the page from the start (the
    top of the body of the dialog, app/inventory.blade.php), since a region that is added together with its message is not
    reliably announced. The error is a role="alert" on purpose, which is announced when it appears.
--}}
<div class="d-flex align-items-center gap-2 py-1">
    <template x-if="upload.state === 'sending'">
        <span>
            <span class="spinner-border spinner-border-sm text-secondary" aria-hidden="true"></span>
            <span class="visually-hidden">{{ __('Sending…') }}</span>
        </span>
    </template>
    <div class="app-grow">
        <span class="text-break" x-text="upload.name"></span>
        <span class="text-secondary small" x-show="upload.state === 'queued'">{{ __('Waiting…') }}</span>
        <div class="text-danger small" role="alert" x-show="upload.message" x-text="upload.message"></div>
    </div>
    <template x-if="upload.state === 'error' && upload.retryable">
        <button type="button" class="btn btn-sm" :aria-label="retryLabel(upload)" :disabled="busy || uploading" @click="retryUpload(upload)">{{ __('Try again') }}</button>
    </template>
    <template x-if="upload.state === 'error'">
        <button type="button" class="btn-close" :aria-label="dismissLabel(upload)" @click="dropUpload(upload)"></button>
    </template>
</div>
