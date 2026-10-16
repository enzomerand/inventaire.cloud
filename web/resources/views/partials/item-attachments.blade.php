{{--
    The attachments tab of the dialog of an item: every file the item holds (the ones of its photo and file fields and the
    ones attached to the item itself) with its thumbnail or icon, its name, its size, the day it was added and, when the API
    says, who added it. Everybody who can see the item downloads; the people who may edit also add files and delete them
    (deleting asks first, since the file is erased).

    Included in the dialog of an item (app/inventory.blade.php), so it reads the inventoryItems component
    (resources/js/components/inventory-items.js, with resources/js/item/files.js).

    The links to a file carry rel="noopener" and never "noreferrer": the file is served by the API on this very origin, and
    without a Referer the request of the new tab is not stateful for Sanctum, so the API answers 401 instead of the file.
    (The Referrer-Policy of the app is same-origin: the Referer never leaves the site.)

    Do not put a display utility (d-flex...) on an element that carries x-show or :hidden: the utility wins and it never hides.
--}}
<div>
    <div class="mb-3" x-show="canAttach">
        <button type="button" class="btn btn-sm" @click="pickAttachments">
            <x-tabler-upload aria-hidden="true" />
            {{ __('Add a file') }}
        </button>
    </div>

    <p class="text-secondary m-0" x-show="noAttachments">{{ __('No attachment yet.') }}</p>

    <ul class="list-group list-group-flush">
        <template x-for="file in attachmentRows" :key="file.id">
            <li class="list-group-item px-0">
                <div class="d-flex align-items-center gap-3">
                    <a :href="file.href" :target="file.target" rel="noopener" tabindex="-1" aria-hidden="true">
                        <template x-if="file.thumb">
                            <img class="app-thumb-lg" :src="file.thumb" alt="" width="72" height="72" loading="lazy" decoding="async">
                        </template>
                        <template x-if="!file.thumb">
                            <span class="app-thumb-lg app-file-icon text-secondary">
                                @include('partials.file-icon')
                            </span>
                        </template>
                    </a>
                    <div class="app-grow">
                        <a class="fw-bold text-break" :href="file.href" :target="file.target" rel="noopener" x-text="file.name"></a>
                        <div class="text-secondary small">
                            <span x-text="file.sizeText"></span>
                            <span x-show="file.added"> · <span x-text="file.added"></span></span>
                            <span x-show="file.addedBy"> · <span x-text="file.addedBy"></span></span>
                        </div>
                        <div class="text-secondary small text-break" x-show="file.fieldName" x-text="file.fieldName"></div>
                    </div>
                    <div class="btn-list flex-nowrap">
                        <a class="btn btn-sm btn-icon" :href="file.download" :aria-label="downloadLabel(file)" :title="downloadLabel(file)">
                            <x-tabler-download aria-hidden="true" />
                        </a>
                        <template x-if="canEdit">
                            <button type="button" class="btn btn-sm btn-icon btn-outline-danger" :aria-label="removeFileLabel(file)" :title="removeFileLabel(file)" :disabled="busy || uploading" @click="askRemove(file)">
                                <x-tabler-trash aria-hidden="true" />
                            </button>
                        </template>
                    </div>
                </div>
            </li>
        </template>
        <template x-for="upload in itemUploads" :key="upload.key">
            <li class="list-group-item px-0">
                @include('partials.item-upload-row')
            </li>
        </template>
    </ul>
</div>
