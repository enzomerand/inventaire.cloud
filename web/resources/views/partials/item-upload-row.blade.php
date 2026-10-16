{{--
    A file on its way up, or one that was refused: its name, a spinner while it is sent, and why it failed. A refused file
    stays until the person dismisses it.

    Included in a template that has an "upload" in scope: the photo and file fields of the dialog of an item
    (partials/item-file-field) and its attachments tab (partials/item-attachments).
--}}
<div class="d-flex align-items-center gap-2 py-1">
    <template x-if="upload.state === 'sending'">
        <span>
            <span class="spinner-border spinner-border-sm text-secondary" aria-hidden="true"></span>
            <span class="visually-hidden" role="status">{{ __('Sending…') }}</span>
        </span>
    </template>
    <div class="app-grow">
        <span class="text-break" x-text="upload.name"></span>
        <div class="text-danger small" role="alert" x-show="upload.message" x-text="upload.message"></div>
    </div>
    <template x-if="upload.state === 'error'">
        <button type="button" class="btn-close" :aria-label="dismissLabel(upload)" @click="dropUpload(upload)"></button>
    </template>
</div>
